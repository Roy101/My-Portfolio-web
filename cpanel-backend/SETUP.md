# cPanel setup — website + database admin

You upload **two things** into `public_html`:
1. **The website** (the React build zip) — the pages visitors see.
2. **The backend** (this `cpanel-backend` folder) — the database API + `/admin` editor.

After this, you edit content at **palashroy.me/admin** and it goes live within a minute — no rebuild, no re-upload.

---

## STEP 1 — Create the database (phpMyAdmin)
1. cPanel → **MySQL® Databases**.
2. **Create New Database** → name it e.g. `portfolio`. Note the full name (like `palashr5_portfolio`).
3. **Add New User** → pick a username + a strong password. Note them.
4. **Add User To Database** → select the user + database → grant **ALL PRIVILEGES**.
5. cPanel → **phpMyAdmin** → click your database on the left → **Import** tab → choose **`schema.sql`** (from this folder) → **Go**.
   - This creates the `content` and `admin_users` tables and fills in all your current content.

## STEP 2 — Upload the backend files
1. cPanel → **File Manager** → `public_html`.
2. Upload these from `cpanel-backend/` into `public_html` (keep the folders):
   - `api/`  (contains content.php, db.php, config.sample.php, .htaccess)
   - `admin/`  (contains index.php)
   - `create-admin.php`
   - *(you do NOT need to upload `schema.sql` — it was only for the import; don't leave it on the server)*
3. In `public_html/api/`, **copy `config.sample.php` → `config.php`** and edit `config.php` with the
   database name / user / password from Step 1. Save.
   - **Extra-secure option:** instead of `api/config.php`, put the file **one level above `public_html`**
     and name it **`portfolio-config.php`**. The code checks there first, so your DB password never lives
     in a web-served folder at all. (Either location works.)

## STEP 3 — Create your admin login
1. Visit **https://palashroy.me/create-admin.php** once.
2. Enter a username (3+ chars) and a **strong** password (10+ chars) → **Create admin**.
3. It creates the account and then **deletes itself automatically**. (It also refuses to run once an
   admin exists, so it can't be abused.) If it's still listed in File Manager afterward, delete it.

## STEP 4 — Upload the website
1. Extract the website zip (`palashroy-cpanel.zip`) into `public_html` (index.html, assets/, images/, papers/, .htaccess…).
   Let it overwrite older site files. Keep your new `api/` and `admin/` folders.
2. Visit **https://palashroy.me** — the site loads and now pulls its content from the database.

## STEP 5 — Use it
- Go to **https://palashroy.me/admin** → log in.
- Edit Publications, Achievements, News, In the News, Pictures, Leadership, Service, References.
- Click **Save** on a section → live within ~1 minute (the site caches content for 60s).

---

## Notes
- **Pictures:** to add a new photo, upload the image via File Manager into `public_html/images/…`, then in
  `/admin → Pictures` add an item and set its **image** field to that path (e.g. `/images/my-photo.jpg`).
- **Citation metrics** (31 citations, h-index…) still come from the website build, not the database, so they
  stay accurate automatically.
- **Structured data (SEO):** the prerendered HTML + JSON-LD reflect the content as of the last website build.
  New items you add via /admin show to visitors and Google immediately; to also refresh the JSON-LD, rebuild
  the site occasionally (or ask me to).

See **SECURITY.md** for the security checklist.
