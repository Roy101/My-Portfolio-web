# Security — what's built in, and your checklist

## Built into the code
- **No SQL injection:** every query uses PDO **prepared statements** with bound parameters
  (`ATTR_EMULATE_PREPARES => false`). No user input is ever concatenated into SQL.
- **Passwords:** stored only as **bcrypt hashes** (`password_hash`), verified with `password_verify`.
  The plain password is never stored.
- **Brute-force protection:** 5 failed logins locks that account for 10 minutes.
- **Sessions:** cookie is `HttpOnly`, `Secure` (HTTPS-only), `SameSite=Strict`; the session ID is
  regenerated on login (prevents session fixation).
- **CSRF:** login form and every save carry a per-session token, checked with `hash_equals`.
- **Authorization:** the save endpoint refuses anything without a valid admin session.
- **Input allow-list:** only the known content sections can be written; data must be valid JSON.
- **Secrets protected:** DB credentials load from **above the web root** if you place them there
  (`portfolio-config.php`), otherwise from `api/config.php` which is blocked by `api/.htaccess` and
  git-ignored. Either way credentials are never committed.
- **Safe provisioning:** `create-admin.php` is a one-time form that **refuses to run once an admin
  exists** and **deletes itself** after use — it cannot be replayed to overwrite your login.
- **Read API is read-only:** `api/content.php` only runs a fixed `SELECT`; it cannot modify anything.
- **Live public pages (`api/seo/`) are read-only too:** `serve.php` only reads the `content` table and its
  own cache. URLs are matched against fixed patterns (paper slugs `^[a-z0-9]+(-[a-z0-9]+)*$`, blog slugs by
  exact match against the published posts); cache file names are hashes, never parts of the URL; file probes
  (image sizes, PDFs) use `realpath` and stay inside the web root, outside `api/`, `admin/` and `_seo/`.
  Unknown addresses get one cached 404 page and create no files. Every value is HTML-escaped exactly as the
  build's generator does; JSON-LD escapes `<`. `api/seo/lib/`, `cli.php`, `api/cache/` and `_seo/` are not
  web-readable, and `serve.php` answers 404 when called by its own address (so maintenance mode cannot be bypassed).
- **Homepage snapshot (`action=seo_home_snapshot`):** only a logged-in admin with the CSRF token can post it,
  and only for the content version it was made from. It must be the homepage (`#main`, the hero) and is refused
  when it contains any script, frame, object, embed, style, meta, link or base element, an event-handler or
  form-target attribute, a `javascript:`/`vbscript:`/`data:text/html` URL, or any character reference other
  than the five the browser itself writes. Markdown links in admin text keep only `http(s):`, `mailto:`,
  same-site `/…` (never `//host` or `/\host`) and `#` targets.
- **Do-not-publish list** (`seo_block_title_sha256`): checked by the admin on every Publications save (refuses
  the save, and refuses it when the check itself fails), by `api/content.php` (never sends such a paper) and by
  the live pages. `api/content.php` also never sends documents marked hidden.
- **Uploaded photos lose their metadata:** location (GPS), camera serial numbers, time stamps, IPTC/XMP and
  comments are removed from JPEG/PNG/WebP uploads, with or without the GD extension (a JPEG keeps only its
  orientation). Note that a file at `/uploads/…` stays reachable by its address until you delete it there.
- **Temp-folder fallbacks** (cache and metrics) are used only when the folder belongs to this account and is
  private (mode 0700); otherwise nothing is cached there. The `X-SEO-Cache` debug header is off by default.

## Your one-time checklist on cPanel
- [ ] Use a **strong, unique admin password** (12+ chars).
- [ ] After creating the admin user, **delete `create-admin.php`** from the server.
- [ ] **Do not** upload `schema.sql` to the public site (import it in phpMyAdmin, then leave it off the server).
- [ ] Confirm the site is **HTTPS** (your `.htaccess` already forces it) — the admin needs HTTPS.
- [ ] In `config.php`, give the DB user only the privileges it needs (ALL on this one database is fine).
- [ ] Keep cPanel's **PHP version** current (8.1 or newer recommended; the live pages need 7.4+) — cPanel → MultiPHP Manager.
- [ ] Set **`display_errors = Off`** and `log_errors = On` (cPanel → MultiPHP INI Editor) so errors never print.
- [ ] Verify `https://palashroy.me/api/config.php` returns **403/empty** (not your credentials).
- [ ] Verify `https://palashroy.me/create-admin.php` is **gone** (404) after first-time setup.

## Good to know
- The admin is at `/admin`; the site itself is static files, so the only "attackable" surface is the
  login. With a strong password + the lockout above, that surface is small.
- If you ever suspect trouble, change the admin password by re-running the `create-admin.php` step
  (then delete the file again), and rotate the DB password in cPanel + `config.php`.
