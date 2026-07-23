<?php
/**
 * Content admin for palashroy.me
 * Login-protected editor that reads/writes the `content` MySQL table.
 * Security: PDO prepared statements, password_hash/verify, CSRF tokens,
 * login lockout, HttpOnly+Secure+SameSite session cookie.
 */
declare(strict_types=1);
require __DIR__ . '/../api/db.php';

// ---- secure session ----
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function require_csrf(string $token): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Bad CSRF token — reload the page.']);
        exit;
    }
}
function is_logged_in(): bool { return !empty($_SESSION['admin']); }
function json_out($data): void { header('Content-Type: application/json'); echo json_encode($data); exit; }

$action = $_GET['action'] ?? '';

// ---- logout ----
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// ---- login ----
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    require_csrf($_POST['csrf'] ?? '');
    $err = 'Invalid username or password.';
    try {
        $stmt = db()->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$user]);
        $row = $stmt->fetch();
        $now = time();
        if ($row && (int)$row['locked_until'] > $now) {
            $err = 'Too many attempts. Try again in a few minutes.';
        } elseif ($row && password_verify($pass, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = $row['username'];
            db()->prepare("UPDATE admin_users SET failed_attempts=0, locked_until=0 WHERE id=?")->execute([$row['id']]);
            header('Location: index.php');
            exit;
        } elseif ($row) {
            $fa = (int)$row['failed_attempts'] + 1;
            $lock = $fa >= 5 ? $now + 600 : 0; // lock 10 min after 5 fails
            db()->prepare("UPDATE admin_users SET failed_attempts=?, locked_until=? WHERE id=?")->execute([$fa, $lock, $row['id']]);
        }
    } catch (Throwable $e) {
        $err = 'Server error. Check config.php / database.';
    }
    $_SESSION['login_error'] = $err;
    header('Location: index.php');
    exit;
}

// ---- save a section (AJAX, logged-in only) ----
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) { http_response_code(401); json_out(['error' => 'Not logged in']); }
    $body = json_decode(file_get_contents('php://input'), true);
    require_csrf($body['csrf'] ?? '');
    $section = $body['section'] ?? '';
    if (!in_array($section, ALLOWED_SECTIONS, true)) { http_response_code(400); json_out(['error' => 'Unknown section']); }
    $json = json_encode($body['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { http_response_code(400); json_out(['error' => 'Invalid data']); }
    try {
        db()->prepare("INSERT INTO content (section,data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)")
            ->execute([$section, $json]);
        json_out(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500); json_out(['error' => 'Database error']);
    }
}

// ================= LOGIN PAGE =================
if (!is_logged_in()) {
    $err = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <title>Admin Login</title>
    <style>body{font-family:system-ui,sans-serif;background:#0c0d16;color:#e8e8ef;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
    form{background:#181a22;border:1px solid #2d324b;padding:32px;border-radius:12px;width:320px}
    h1{font-size:20px;margin:0 0 20px}input{width:100%;box-sizing:border-box;padding:10px;margin-bottom:12px;background:#0c0d16;border:1px solid #2d324b;border-radius:8px;color:#fff}
    button{width:100%;padding:11px;background:linear-gradient(90deg,#35c7ff,#ff4081);border:0;border-radius:8px;color:#fff;font-weight:600;cursor:pointer}
    .err{color:#ff6b6b;font-size:13px;margin-bottom:12px}</style></head><body>
    <form method="post" action="index.php?action=login">
      <h1>Content Admin</h1>
      <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input name="username" placeholder="Username" autocomplete="username" required autofocus>
      <input name="password" type="password" placeholder="Password" autocomplete="current-password" required>
      <button type="submit">Log in</button>
    </form></body></html><?php
    exit;
}

// ================= EDITOR =================
$content = [];
try {
    foreach (db()->query("SELECT section, data FROM content")->fetchAll() as $r) {
        $content[$r['section']] = json_decode($r['data'], true);
    }
} catch (Throwable $e) { $content = []; }

// field schemas: which keys each section's items have, and which are long text
$schemas = [
    'publications' => ['title'=>'text','authors'=>'text','venue'=>'text','year'=>'text','pages'=>'text','award'=>'text','doi'=>'text','description'=>'area','preprint'=>'text'],
    'highlights'   => ['title'=>'text','organization'=>'text','description'=>'area','link'=>'text'],
    'news'         => ['date'=>'text','icon'=>'text','title'=>'text','description'=>'area'],
    'media'        => ['outlet'=>'text','date'=>'text','title'=>'text','url'=>'text'],
    'gallery'      => ['image'=>'image','title'=>'text','description'=>'area','altText'=>'text'],
    'leadership'   => ['period'=>'text','role'=>'text','organization'=>'text','place'=>'text','link'=>'text'],
    'service'      => ['period'=>'text','role'=>'text','description'=>'area','venue'=>'text','venues'=>'text'],
    'references'   => ['name'=>'text','title'=>'text','image'=>'image','text'=>'area'],
];
?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Content Admin · palashroy.me</title>
<style>
:root{color-scheme:dark}
body{font-family:system-ui,sans-serif;background:#0c0d16;color:#e8e8ef;margin:0}
header{position:sticky;top:0;background:#12131c;border-bottom:1px solid #2d324b;padding:12px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;z-index:10}
header h1{font-size:16px;margin:0;flex:1}
nav a{color:#a2a5b9;margin-right:12px;text-decoration:none;font-size:14px;cursor:pointer}
nav a.active{color:#7ec8e3;font-weight:600}
a.logout{color:#ff6b6b;text-decoration:none;font-size:14px}
main{max-width:820px;margin:20px auto;padding:0 16px}
.section{display:none}.section.active{display:block}
.item{background:#181a22;border:1px solid #2d324b;border-radius:10px;padding:16px;margin-bottom:14px}
label{display:block;font-size:12px;color:#a2a5b9;margin:8px 0 4px;text-transform:capitalize}
input,textarea{width:100%;box-sizing:border-box;padding:8px;background:#0c0d16;border:1px solid #2d324b;border-radius:6px;color:#fff;font-family:inherit}
textarea{min-height:70px;resize:vertical}
.item-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.item-head b{font-size:13px;color:#7ec8e3}
button{background:#2d324b;border:0;color:#fff;padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px}
button.primary{background:linear-gradient(90deg,#35c7ff,#ff4081);font-weight:600}
button.del{background:#3a1420;color:#ff6b6b}
.bar{display:flex;gap:10px;margin:16px 0;position:sticky;bottom:0;background:#0c0d16;padding:12px 0}
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#181a22;border:1px solid #2d324b;padding:10px 18px;border-radius:8px;opacity:0;transition:.3s}
.toast.show{opacity:1}
img.thumb{max-height:44px;border-radius:4px;margin-top:6px}
small{color:#6b6f85}
</style></head><body>
<header>
  <h1>Content Admin</h1>
  <nav id="tabs"></nav>
  <a class="logout" href="index.php?action=logout">Log out</a>
</header>
<main id="app"></main>
<div class="toast" id="toast"></div>
<script>
const CSRF = <?= json_encode(csrf()) ?>;
const SCHEMAS = <?= json_encode($schemas) ?>;
const DATA = <?= json_encode($content, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const LABELS = {publications:'Publications',highlights:'Achievements',news:'News & Milestones',media:'In the News',gallery:'Pictures',leadership:'Leadership',service:'Academic Service',references:'References'};
const sections = Object.keys(SCHEMAS);
let current = sections[0];

function toast(msg){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200);}

function renderTabs(){
  document.getElementById('tabs').innerHTML = sections.map(s =>
    `<a class="${s===current?'active':''}" onclick="switchTo('${s}')">${LABELS[s]}</a>`).join('');
}
window.switchTo = s => { current = s; renderTabs(); renderSection(); };

function fieldHtml(section, idx, key, type, val){
  const id = `${section}__${idx}__${key}`;
  val = val == null ? '' : String(val);
  const esc = v => v.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
  if(type==='area') return `<label>${key}</label><textarea data-k="${key}" oninput="mark()">${esc(val)}</textarea>`;
  let extra = type==='image' && val ? `<img class="thumb" src="${esc(val)}">` : '';
  return `<label>${key}</label><input data-k="${key}" value="${esc(val)}" oninput="mark()">${extra}`;
}

function renderSection(){
  const schema = SCHEMAS[current];
  const items = DATA[current] || [];
  const app = document.getElementById('app');
  app.innerHTML = `<h2>${LABELS[current]}</h2>` +
    `<div id="items">` + items.map((it,i)=>itemHtml(current,i,it)).join('') + `</div>` +
    `<div class="bar"><button onclick="addItem()">+ Add ${LABELS[current].replace(/s$/,'')}</button>` +
    `<button class="primary" onclick="save()">Save ${LABELS[current]}</button></div>` +
    `<small>Changes go live on the website within a minute of saving.</small>`;
}
function itemHtml(section,i,it){
  const schema = SCHEMAS[section];
  const fields = Object.entries(schema).map(([k,t])=>fieldHtml(section,i,k,t,it[k])).join('');
  const title = it.title||it.name||it.role||it.date||('Item '+(i+1));
  return `<div class="item" data-i="${i}"><div class="item-head"><b>${title.replace(/</g,'&lt;')}</b>`+
    `<button class="del" onclick="delItem(${i})">Remove</button></div>${fields}</div>`;
}
function collect(){
  const schema = SCHEMAS[current];
  return [...document.querySelectorAll('#items .item')].map(el=>{
    const o={};
    el.querySelectorAll('[data-k]').forEach(inp=>{ const v=inp.value.trim(); if(v!=='') o[inp.dataset.k]=v; });
    return o;
  });
}
window.addItem = ()=>{ DATA[current]=collect(); DATA[current].push({}); renderSection(); };
window.delItem = i=>{ DATA[current]=collect(); DATA[current].splice(i,1); renderSection(); };
window.mark = ()=>{};
window.save = async ()=>{
  DATA[current]=collect();
  try{
    const r = await fetch('index.php?action=save',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({csrf:CSRF,section:current,data:DATA[current]})});
    const j = await r.json();
    toast(j.ok ? 'Saved ✓' : ('Error: '+(j.error||'failed')));
  }catch(e){ toast('Network error'); }
};
renderTabs(); renderSection();
</script></body></html>
