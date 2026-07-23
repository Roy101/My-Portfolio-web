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
- **Secrets protected:** `config.php` (DB credentials) is blocked from the web by `api/.htaccess`
  and git-ignored so it is never committed.
- **Read API is read-only:** `api/content.php` only runs a fixed `SELECT`; it cannot modify anything.

## Your one-time checklist on cPanel
- [ ] Use a **strong, unique admin password** (12+ chars).
- [ ] After creating the admin user, **delete `create-admin.php`** from the server.
- [ ] **Do not** upload `schema.sql` to the public site (import it in phpMyAdmin, then leave it off the server).
- [ ] Confirm the site is **HTTPS** (your `.htaccess` already forces it) — the admin needs HTTPS.
- [ ] In `config.php`, give the DB user only the privileges it needs (ALL on this one database is fine).
- [ ] Keep cPanel's **PHP version** current (8.x) — cPanel → MultiPHP Manager.
- [ ] Verify `https://palashroy.me/api/config.php` returns **403/empty** (not your credentials).

## Good to know
- The admin is at `/admin`; the site itself is static files, so the only "attackable" surface is the
  login. With a strong password + the lockout above, that surface is small.
- If you ever suspect trouble, change the admin password by re-running the `create-admin.php` step
  (then delete the file again), and rotate the DB password in cPanel + `config.php`.
