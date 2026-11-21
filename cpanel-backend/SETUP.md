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

---

## Applying the 2026-11 redesign update

The 2026-11 website build comes with a content update for the database: cleaned-up headings and
bio text (no emoji, consistent lab names and title), the 10 verified publications with their new
fields (abstract, DOI, arXiv, code, tool, artifact links), merged News/Awards entries, the FAQ, and
Gallery + References hidden by default. The live site reads the database, so until you apply this
the new design shows your old database text.

1. Upload the updated backend files (keep your `api/config.php`):
   `admin/index.php`, `admin/migrations/` (the folder, including its `.htaccess`) and `api/db.php`
   (it now allows the `faq` section).
2. Log in at **/admin** and open **Site → Maintenance & Backup**.
3. Click **Download full content backup (JSON)** and keep the file.
4. Under **Content update**, click **Preview changes**. Each change shows the current value and the
   new one. Changes to fields you have edited yourself are marked **you edited this** and are left
   unticked; tick them only if you want the new text. Untick anything you want to keep.
5. Click **Download backup, then apply …** and confirm. A second backup downloads automatically,
   then each changed section is saved through the normal admin save. The site updates within a minute.
6. If something looks wrong, use **Restore from a backup file** on the same page with the backup
   from step 3 (you can restore single sections).

Notes:
- Running the update again is safe: anything already applied is skipped.
- Publications are matched by DOI, then slug, then title. Missing papers are added and empty fields
  are filled; your own non-empty values are kept, except the listed corrections (e.g. title casing,
  bare DOIs, and links to IEEE Xplore PDFs that may not be posted).
- The four removed PDFs (`07710-AAAI24.DuY.pdf`, `A_Study_on_Paper_and_Author_Ranking.pdf`,
  `Are_large_language.pdf`, `Towards_Just-In-Time_Inclusive_Clone_Refactoring.pdf`) are no longer in
  the website build. Delete them from `public_html/papers/` on the server too. To link a PDF for an
  IEEE paper, upload the **accepted manuscript** (not the IEEE Xplore download) in the publication's
  `pdf` field.

---

## Applying the 2026-11 round 2 update

Round 2 adds documents (CV and statements), a Software & Data list, Methods & tools, selected
papers, maintenance mode, and removes repeated content (each role, award and fact appears once).

1. Upload the updated backend files (keep your `api/config.php`):
   `admin/index.php`, `admin/upload.php`, `admin/migrations/2026-11-round2.json`, `api/db.php`
   (allows the new `documents`, `skills` and `software` sections) and `api/content.php`
   (returns only the public content sections).
2. In **/admin → Site → Maintenance Mode & Backup**, apply **2026-11 redesign** first if you have not
   done so, then pick **2026-11 round 2** in the Update list → **Preview changes** → apply. It works
   like the first update: a backup downloads first, fields you edited yourself are left unticked, and
   running it twice changes nothing.
3. Check the new editors under **Content**: Documents, Software & Data, Methods & Tools (review every
   line; it was filled in from your papers and code repositories), Research Statement & Themes, and
   the **selected** box on each publication.

## Applying the 2026-11 round 3 update

Round 3 adds your M.Sc. thesis (with its official abstract from USask HARVEST), marks the ICSME 2025
Just-In-Time paper as a Doctoral Symposium paper with its CLEAR tool link, renames Software & Data
to **Tools & Benchmarks** (only MergeSE and GPTCloneBench, which have their own tool/benchmark
papers), adds two kinds of tags on each paper (software engineering research areas and AI/ML topics,
with a filter on /publications/), a curated "Latest" list near the top (pinned items only; the ASE 2026
news is pinned), co-first authorship on GPTCloneBench
and the IWSC 2023 paper, PhD expected Aug 2028, the M.Sc. mentoring and NSERC USRA supervision rows,
the CARA invited talk, and splits the IWSC 2023 award into its two awards.

1. Upload the updated backend files (keep your `api/config.php`):
   `admin/index.php`, `admin/upload.php`, `admin/migrations/2026-11-round3.json` and the new
   `uploads/.htaccess` (upload it into `public_html/uploads/`; it stops anything in that folder from
   running as code and serves slides and videos with the right type).
2. In **/admin → Site → Maintenance Mode & Backup**, apply **2026-11 redesign** and **2026-11 round 2**
   first if you have not, then pick **2026-11 round 3** → **Preview changes** → apply. A backup
   downloads first; fields you edited yourself are left unticked; running it twice changes nothing.
   Old entries are replaced only when they are found by their old text (the combined IWSC award
   title, and the "Research Supervision" row); if you had already changed them, the preview says
   "Not found (skipped)" and nothing is touched.
3. Check: **Publications** (tags, ml_topics, Slides and Video fields, equal_contribution, the thesis
   entry), **Site Settings** (Latest on/off and maximum count 1–3; the research-area and AI/ML topic
   lists; the "Research areas" and "AI/ML topics" rows), **Tools & Benchmarks**, **Biography →
   Education** (Expected completion, Thesis). Only items with the **pinned** box ticked (News,
   Award, Leadership or Academic Service) appear in Latest; untick all of them to hide the strip.

## Applying the 2026-11 round 4 update

Round 4 adds an **In the news** list to `/about/`: the three University of Saskatchewan articles that
mention you (USask News, Oct 2023; Department of Computer Science, Jul 2024 and Apr 2025) and the
SRLab members and iSE Lab team pages. The three articles are also given to search engines as news
coverage of you (`subjectOf`), never as your own profiles.

1. Upload the updated backend files (keep your `api/config.php`):
   `admin/index.php` (new **Biography → In the news** editor) and `admin/migrations/2026-11-round4.json`.
2. In **/admin → Site → Maintenance Mode & Backup**, apply the updates in this order if you have not
   yet: **2026-11 redesign** → **2026-11 round 2** → **2026-11 round 3** → **2026-11 round 4**
   (**Preview changes** → apply, each once). A backup downloads first. Round 4 only fills the list when
   it is empty, and running it twice changes nothing.
3. Rebuild and upload the website (the `/about/` page and the search data are generated at build time).
   To add a later article, use **Biography → In the news**: the headline exactly as on the page, the
   link, the publisher and the date (YYYY-MM-DD); choose "Lab profile page" for people/team pages.

## Slides and videos for papers

Each publication has **slides_url** (Presentation slides) and **video** (Presentation video). A
"Slides" or "Video" link appears on the website only when the field is filled in; **Clear** removes it.

- **Upload PDF / PPTX** (slides, max 50 MB) or **Upload MP4 / WebM** (video, max 200 MB), or paste a
  link instead: SpeakerDeck / SlideShare / Google Slides for slides, YouTube / Vimeo for videos
  (recommended for videos: no size limit, and streaming does not use your hosting bandwidth).
- Uploads are checked twice: the file name must end in an allowed extension **and** the file content
  must really be that type (a renamed file is refused; PowerPoint files with macros are refused).
  The server picks the stored name (readable part + random part); files go to `public_html/uploads/`.

**Upload size limits (cPanel).** PHP's own limits apply before the admin's: `upload_max_filesize`
(largest single file) and `post_max_size` (largest whole request; must be a bit larger than
`upload_max_filesize`). Many cPanel accounts default to 2M–64M. The admin shows the current values
next to the upload buttons. To raise them: **cPanel → Software → MultiPHP INI Editor → Basic Mode**,
choose your domain, then set for example `upload_max_filesize = 200M`, `post_max_size = 210M`
(and `max_execution_time = 300`, `max_input_time = 300` for slow connections) and click **Apply**.
If an upload says "File too large for this server", these are the values to raise.

## Documents (CV, research and teaching statements)

**/admin → Documents** lists the buttons shown in the hero and the Contact section (the CV also
appears in the top menu and footer). Click **Upload PDF** on a row (PDF only, max 20 MB) or paste a
link. A button appears only when its link is filled in, so the three default rows stay hidden until
you upload. Uploaded files are stored in `public_html/uploads/` with a readable name plus a random
part (for example `palash-ranjan-roy-cv-1a2b3c4d5e6f7a8b.pdf`). The older **Site Settings →
Resume / CV** link is still used for the CV button when Documents has no CV link.

The admin's own limit is 20 MB per PDF (8 MB per image), but the server's PHP limits apply first:
`upload_max_filesize` and `post_max_size` (often 2 to 64 MB on cPanel). If an upload says "File too
large for this server", raise both in **cPanel → MultiPHP INI Editor** (see "Slides and videos for
papers" above) or use a smaller file.

## Maintenance mode

**/admin → Maintenance Mode & Backup → Maintenance mode** has an ON/OFF switch (with a confirmation),
an optional message and an optional "expected back" time.

- **ON** creates the flag file `public_html/.maintenance` and writes your message into
  `public_html/maintenance.html`. Apache (`.htaccess`) then answers every page request with
  **HTTP 503** and `Retry-After: 3600`, showing `maintenance.html`. A 503 tells Google the outage is
  temporary, so rankings are kept. PHP is not involved on visitor requests.
- **Not affected:** `/admin/`, `/api/`, `maintenance.html` itself, fonts, images, icons,
  `robots.txt`, and **you**: logging in to the admin sets a private cookie (`pr_maint_bypass`, a
  random 48-character token) so your browser keeps showing the real site. While maintenance is on,
  the admin also writes an empty marker file `public_html/.maint-ok-<token>`; Apache lets the cookie
  through only when the marker for that exact token exists, so a made-up cookie does not work.
  Logging out removes the cookie. To see what visitors see, use a private window.
- **OFF** deletes the flag file and the `.maint-ok-*` marker; the site is live again at once.
- The state is also saved in the `settings` section, so the admin shows it. If the admin warns that
  the saved state and the flag file disagree, switch again. You can also delete or create
  `public_html/.maintenance` by hand in File Manager (it is a hidden file).
- PHP must be able to write to `public_html` (folder permission 755, which is the cPanel default).
- Uploading a new website build replaces `maintenance.html` with the default text. If maintenance
  mode is on at that moment, click **Update message** again.

## When changes appear

The homepage and blog read the database, so edits show within about a minute. Generated pages
(`/publications/`, each paper page, `/faq/`, `/photos/`, `/about/`, the static blog pages,
`llms.txt`, `llms-full.txt` and `sitemap.xml`) are built from the content at build time: rebuild
(`npm run build`) and upload `dist/` to refresh them after editing papers (including tags, slides,
videos and co-first authors), the FAQ, photos, socials, Tools & Benchmarks or your biography.
