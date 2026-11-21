<?php
// Visitor-country counter: shared helpers for api/visit.php and the admin "Visitors" panel.
// Privacy: the statistics are only (day, country, count). No IP address, cookie, user agent or
// time zone string is stored. The abuse limit (visit_once_today) keeps, for the current UTC day
// only, a 16-byte HMAC of the visitor's SHORTENED address (IPv4 /24, IPv6 /48, so even a
// reversed hash reveals only a network, never one device) under a random key created that day.
// Key and HMACs from earlier days are deleted on the first visit of a new day, on the public
// summary and when the admin panel opens. They can still appear in that day's database backups.
// Not web-served (api/.htaccess).

const VISIT_DAILY_NETWORKS = 20000; // stop counting for the day after this many networks
const VISIT_COUNTRY_DAY_CAP = 2000; // at most this many visits per country per day

function visit_create_tables(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS `visit_stats` (
        `day` DATE NOT NULL, `country` CHAR(2) NOT NULL, `hits` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`day`,`country`)) ENGINE=InnoDB DEFAULT CHARSET=ascii");
    $db->exec("CREATE TABLE IF NOT EXISTS `visit_salt` (
        `day` DATE NOT NULL PRIMARY KEY, `salt` BINARY(32) NOT NULL) ENGINE=InnoDB");
    $db->exec("CREATE TABLE IF NOT EXISTS `visit_seen` (
        `day` DATE NOT NULL, `h` BINARY(16) NOT NULL, PRIMARY KEY (`day`,`h`)) ENGINE=InnoDB");
}

// Kept for the admin panel (runs rarely): create the tables if they are missing.
function visit_ensure_tables(PDO $db): void
{
    static $done = false;
    if ($done) return;
    visit_create_tables($db);
    $done = true;
}

// Runs $fn; if a table is missing (SQLSTATE 42S02, first use), creates the tables and retries once.
function visit_with_tables(PDO $db, callable $fn)
{
    try { return $fn(); }
    catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;
        visit_create_tables($db);
        return $fn();
    }
}

function visit_today(): string { return gmdate('Y-m-d'); }

// Deletes keys and HMACs from any day before today.
function visit_purge_old(PDO $db): void
{
    $day = visit_today();
    $db->prepare("DELETE FROM visit_salt WHERE day < ?")->execute([$day]);
    $db->prepare("DELETE FROM visit_seen WHERE day < ?")->execute([$day]);
}

// Network part of an address: IPv4 /24, IPv6 /48 (one home, office or provider block).
// Returns '' for anything that is not a valid address.
function visit_network(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin === false) return '';
    if (strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") $bin = substr($bin, 12); // IPv4-mapped
    return strlen($bin) === 4 ? substr($bin, 0, 3) . "\0" : substr($bin, 0, 6) . str_repeat("\0", 10);
}

// True the first time this network is seen today (UTC), false afterwards or once the daily cap is hit.
function visit_once_today(PDO $db, string $ip): bool
{
    $net = visit_network($ip);
    if ($net === '') return false;
    $day = visit_today();
    $get = $db->prepare("SELECT salt FROM visit_salt WHERE day = ?");
    $get->execute([$day]);
    $salt = (string)$get->fetchColumn();
    if (strlen($salt) !== 32) {
        // First visit of the day: create today's key; the request that creates it removes old days.
        $ins = $db->prepare("INSERT IGNORE INTO visit_salt (day, salt) VALUES (?, ?)");
        $ins->execute([$day, random_bytes(32)]);
        if ($ins->rowCount() === 1) visit_purge_old($db);
        $get->execute([$day]);
        $salt = (string)$get->fetchColumn();
        if (strlen($salt) !== 32) return false;
    }
    $n = $db->prepare("SELECT COUNT(*) FROM visit_seen WHERE day = ?");
    $n->execute([$day]);
    if ((int)$n->fetchColumn() >= VISIT_DAILY_NETWORKS) return false;
    $h = substr(hash_hmac('sha256', $net, $salt, true), 0, 16);
    $ins = $db->prepare("INSERT IGNORE INTO visit_seen (day, h) VALUES (?, ?)");
    $ins->execute([$day, $h]);
    return $ins->rowCount() === 1;
}

function visit_count(PDO $db, string $country): void
{
    $db->prepare("INSERT INTO visit_stats (day, country, hits) VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE hits = IF(hits < " . VISIT_COUNTRY_DAY_CAP . ", hits + 1, hits)")
        ->execute([visit_today(), $country]);
}

// settings.visitorLine from the content table: {show, min, text} with defaults.
function visit_line_settings(PDO $db): array
{
    $out = ['show' => false, 'min' => 10, 'text' => 'Visitors from {n} countries'];
    try {
        $st = $db->prepare("SELECT data FROM content WHERE section = 'settings'");
        $st->execute();
        $s = json_decode((string)$st->fetchColumn(), true);
        $v = is_array($s) && isset($s['visitorLine']) && is_array($s['visitorLine']) ? $s['visitorLine'] : [];
        if (isset($v['show'])) $out['show'] = $v['show'] === true || $v['show'] === 1 || $v['show'] === 'true';
        if (isset($v['min']) && is_numeric($v['min'])) $out['min'] = max(1, (int)$v['min']);
        if (isset($v['text']) && is_string($v['text']) && trim($v['text']) !== '') $out['text'] = trim($v['text']);
    } catch (Throwable $e) { /* defaults */ }
    return $out;
}
