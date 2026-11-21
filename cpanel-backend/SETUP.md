# cPanel setup — website + database admin

You upload **two things** into `public_html`:
1. **The website** (the React build zip) — the pages visitors see.
2. **The backend** (this `cpanel-backend` folder) — the database API + `/admin` editor.

After this, you edit content at **palashroy.me/admin** and it goes live as soon as you save: every public page,
`sitemap.xml`, `llms.txt`, `llms-full.txt`, the Markdown copies and the search data (JSON-LD) are made from the
database on the server (`api/seo/`, see "Live public pages" below). No rebuild, no re-upload, no GitHub.
A new website build is needed only for design or code changes.

The owner's step-by-step lists are `UPDATE-LIVE-SITE.md` (numbered checklist; Phase P = everyday updates)
and `DEPLOY-STEPS.md` (background), kept in `~/Desktop/Portfolio/rollback-2026-11/`.

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
   - `api/`  (content.php, db.php, contact.php, visit*.php, tzcountry.php, countrynames.php, metrics*.php,
     config.sample.php, .htaccess, `cache/.htaccess`, and `seo/`, the live public pages: serve.php, cli.php,
     VERSION.php, .htaccess and `lib/`)
   - `admin/`  (index.php, upload.php, captcha.php, fetchtitle.php, `migrations/`)
   - `uploads/.htaccess`
   - `create-admin.php`
   - The exact zip command (37 entries) is in `UPDATE-LIVE-SITE.md`, Phase B. Upload the backend and the
     website from the same release: the admin refuses to mix them and serves the static pages until they match.
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
1. Extract the website zip (`site.zip`, made from `palash-portfolio/dist/`) into `public_html` (index.html, assets/,
   images/, papers/, .htaccess, and `_seo/`, the page templates the live pages need; never served).
   Let it overwrite older site files. Keep your new `api/` and `admin/` folders.
2. Visit **https://palashroy.me** — the site loads and now pulls its content from the database.
3. In **/admin → Site → Public pages & SEO** the status reads "Live: pages are made from the database." 

## STEP 5 — Use it
- Go to **https://palashroy.me/admin** → log in.
- Edit Publications, Awards & Honors, News & Milestones, In the News, Photos, Documents, Blog Posts, FAQ,
  Leadership, Academic Service, Identity, Home / Hero, Site Settings and the rest.
- Click **Save** on a section → live at once (pages, sitemap, llms files, `.md` copies and search data).

---

## Notes
- **Photos:** `/admin → Photos` → **+ Add Photo** → upload the image (JPG, PNG, WebP or GIF, up to 8 MB; it is
  stored in `public_html/uploads/` with GPS, camera and comment metadata removed) → alt text and caption →
  **Save Photos**. It appears on `/photos/` and in the image sitemap at once. A path to a file you placed in
  `public_html/images/` yourself also works.
- **Citation metrics:** see "Citation metrics (OpenAlex, updated daily)" below.
- **Structured data (SEO):** the JSON-LD on every page is made from the database at request time (cached), so
  a new paper, award or post is in the search data as soon as you save. See "Live public pages" below.

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
   then each changed section is saved through the normal admin save. The site updates at once.
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
3. Nothing to rebuild: `/about/` and the search data update as soon as the update is applied.
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

Rows are shown in the order of the list (↑/↓), and each row has a **Hide** box (hidden documents
appear nowhere on the site, in llms.txt or in `/api/content.php`; the uploaded file itself stays reachable at
its own link, so delete it from `public_html/uploads/` too if it must disappear). Kinds: CV, Résumé, research, teaching, EDI and diversity
statements, teaching portfolio, publication list, cover letter, or **Other** with any label. To
replace a file, click **Upload PDF** again on that row and save: the new file gets a new address, so
the year-long browser cache never shows the old copy.

## Identity, profile photo and page titles (no code needed)

- **/admin → Identity** holds your name, other spellings, "Published as", ORCID, public email, job
  title, summary, labs sentence, scholarly IDs (ORCID, dblp, OpenAlex, …) and organisations. Every
  page, the JSON-LD, llms.txt, the sharing previews, the contact-form error text and the OpenAlex
  metrics (ORCID) use them. Until you save it, the built-in values are used.
- **/admin → Home / Hero → Upload new profile photo** (JPG, PNG or WebP, up to 15 MB). The server
  checks the file is an image, crops it to a centred square and, with the PHP **GD** extension,
  writes WebP copies of 200/240/480/640 px and a 1200 px JPEG (og:image), all with content-hashed
  names. Without GD the original is stored in one size and the admin says so; enable GD in
  **cPanel → Select PHP Version → Extensions → gd** for the fast version. GD is detected at upload time
  (including WebP support); the largest photo it will resize follows the server's `memory_limit`, and EXIF
  rotation is applied after scaling, so the exif extension is not needed. Larger photos are stored as they
  are. Location, camera and comment metadata are removed from JPEG, PNG and WebP uploads with or without GD
  (a JPEG keeps its orientation).
- After a new photo, link previews of the homepage, `/blog/` and posts without a cover use it, and the
  search data lists only that photo.
- **/admin → Site Settings → Search & sharing**: homepage title/description/sharing image, and the
  title and description of /publications/, /faq/, /about/, /photos/ and /blog/, the WebSite
  description and the "knowsAbout" topics. Empty fields keep the built-in text.
- **Sections & Headings** also has the 404 page texts, the /publications/ intro, the empty-blog
  texts, the contact-form thank-you text and the "All awards" link; **Footer** has the CV link
  labels and a `{year}` placeholder for the copyright line; **Maintenance** has the page title.

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

## Visitor countries (optional "Visitors from N countries" line)

**/admin → Site → Visitors** shows roughly where visitors come from, by country (last 30 days,
last 12 months or all time), and has a switch for one small footer line on the homepage and blog,
"Visitors from N countries". The line is **off** by default and appears only once N reaches the
minimum you set (default 10). **Reset statistics** deletes all counts.

How it works, and what is stored:
- The homepage, the blog and the generated pages (`/publications/`, paper pages, `/about/`, `/faq/`,
  `/photos/`) send one tiny request per browser session in total to `api/visit.php` with only the
  browser's time zone name (for example `America/Regina`). The server turns it into a country code
  with `api/tzcountry.php` (generated from the IANA time zone database) and adds 1 to that day's
  count. Stored: **date, country, count**. No IP address, cookie, user agent or time zone string.
- Abuse limit: one counted visit per **network** per day (UTC): the address is first shortened to
  its network part (IPv4 /24, IPv6 /48). For that, a keyed hash (HMAC) of the shortened address is
  kept **for the current day only**, under a random key created that day (tables `visit_salt`,
  `visit_seen`). Key and hashes from earlier days are deleted on the first visit of a new day, when
  the summary is requested and when the admin panel opens. They can appear in that day's cPanel
  database backup; even then a hash only reveals a network, not one device. Several people on one
  network (a university, for example) are therefore counted once per day. Extra caps: at most
  20,000 networks per day and 2,000 visits per country per day.
- The limit uses the connection address (`REMOTE_ADDR`). If the site is ever put behind Cloudflare
  or another proxy/CDN, enable the host's real-visitor-IP support (mod_remoteip / cPanel's
  Cloudflare option) first, otherwise all visitors share the proxy's few addresses and are counted
  only a handful of times per day.
- Not counted: Do Not Track / Global Privacy Control, automated browsers, obvious bots, requests
  from other sites or without an Origin/Referer (browsers always send one), unknown time zones and UTC.
- The country is a guess from the time zone, not a location: VPN users and travellers may be
  counted elsewhere.
- The tables (`visit_stats`, `visit_salt`, `visit_seen`) are **created automatically** on the
  first counted visit; they are also in `schema.sql` for fresh installs.
- `GET /api/visit.php?summary` gives `{"countries": N, "since": "YYYY-MM-DD"}` (N is 0 while the
  switch is off or N is below the minimum) and is cached for one hour, so a change can take up to an hour to show.
- Files: `api/visit.php`, `api/visitlib.php`, `api/tzcountry.php`, `api/countrynames.php` (the
  last three are blocked from direct access by `api/.htaccess`). `api/content.php` reports whether
  `visit.php` exists, so an older backend never receives these requests. To refresh the time zone
  map after a tzdata release: `node cpanel-backend/tools/gen-tzcountry.mjs`.
- The generated pages cannot ask `content.php` first, so they load the small script `/visit.js`
  (deferred, after the page has loaded). The build adds it only when `cpanel-backend/api/visit.php`
  is in the repo: **upload the backend `api/` folder together with the website**, otherwise those
  pages send their one request to a missing `visit.php` (a harmless 404, but avoid it).
- Privacy note: the counter sets no cookies and its statistics hold nothing personal, so it needs
  no cookie banner of its own (only the daily network hash above is short-lived pseudonymous data).
  (Google Analytics, which the site also loads, is separate.) If you wish, add one sentence to the
  site, e.g. "Visits are counted by country only, from the browser's time zone; the counter sets
  no cookies and keeps no IP addresses."

## Citation metrics (OpenAlex, updated daily)

**/admin → Research Metrics** has two modes:
- **Automatic (OpenAlex)**: `api/metrics.php` asks OpenAlex (by ORCID) for citations, h-index and
  works **at most once a day** and keeps the answer in `api/cache/metrics.json`. The homepage loads
  those numbers after the page has loaded, so they update without a rebuild. The admin page shows
  the numbers on the website now and when they were last fetched, and has **Check OpenAlex now**.
  If OpenAlex cannot be reached, the last good numbers stay (a failed attempt is retried after 15
  minutes); before the first successful fetch the site shows the numbers from the last build. The
  "as of" date changes only when a number changes.
- **Manual**: the numbers you type are shown; `metrics.php` answers 204 (empty) and is not asked.

Files: `api/metrics.php`, `api/metricslib.php` (blocked from direct access) and `api/cache/`
(with its own `.htaccess`; also blocked by `api/.htaccess`). PHP must be able to write `api/cache/`
(normal on cPanel; if not, a private folder in the system temp directory is used). The request to
OpenAlex uses cURL (5 s timeout) or, without cURL, `allow_url_fopen`, and sends no personal data.
`api/content.php` reports whether `metrics.php` exists (`_metrics`), so an older backend never gets
these requests. The homepage HTML that search engines read first is the admin's last homepage snapshot
(see "When changes appear"), so its numbers can lag slightly behind the live ones.

## When changes appear

Right after you save in the admin. `api/seo/` renders every public page from the database: the homepage
head and search data, `/publications/` and each paper page (with `cite.bib` and the `.md` copy),
`/publications.md`, `/faq/` and `/faq.md`, `/about/`, `/photos/`, `/blog/` and each post, `sitemap.xml`,
`llms.txt` and `llms-full.txt`. The pages are kept ready in a cache (`api/cache/seo/`) that each save
refreshes, so the next visitor gets them at once. An edit made directly in phpMyAdmin shows within 30 seconds.
HTML pages are sent with `max-age=0`; `sitemap.xml`, `llms.txt`, `llms-full.txt` and the `.md` files with
`max-age=3600`, so a browser that opened one of those in the last hour may need **Ctrl+Shift+R** (crawlers
fetch fresh copies). Search Console needs no action after an edit: Google rereads `sitemap.xml` by itself.
For a new paper, **URL Inspection → Request indexing** on its page is optional and only speeds things up.

A new paper gets its page at `/publications/<slug>/` and a sitemap entry with that day's date; a new
post gets `/blog/<slug>/`; a draft post has a preview page that search engines are told not to index.
A deleted paper's address answers 404. A paper or post saved without a slug gets one from its title on
the first save (shown in the slug field), so fixing a typo in the title later does not move the page; if
you change a slug yourself, the old address redirects (301) to the new one. A paper without a DOI or arXiv
id yet (an accepted paper) is saved with a note; one that the website build would refuse (no title, venue
or year, a malformed DOI, a duplicate slug, ...) is **not saved**: the admin lists what to fix.

**Homepage text for search engines and readers without JavaScript.** The homepage is drawn by the React
app, which PHP cannot reproduce. So after every save the admin page renders the homepage in a hidden frame
in your browser (as the build does) and sends it to the server, which serves it until the next change
(admin → Public pages & SEO shows whether it is up to date, with an **Update homepage text now** button).
Keep the admin open a few seconds after saving. An edit made directly in phpMyAdmin is picked up the next
time you open the admin; until then those readers get the previous text (visitors with JavaScript always
see the newest content).

Only design or code changes need a new website build: React/UI changes, WebP size variants for new files
under `/images/` (`npm run images`; uploads have none), and new page types. Replacing or deleting a file
over FTP or File Manager (a PDF, an image) is not a content change: the pages pick it up at the next save,
or at once with **Rebuild cache now**.

## Live public pages (api/seo/)

Requirements: PHP 7.4 or newer (8.1+ recommended; the page output is tested to be identical to the
build's on PHP 7.4, 8.0 and 8.2) with the mbstring, ctype and json extensions (standard on cPanel; the
admin's Public pages & SEO panel says when one is missing, and the build's static copies are served then).
`api/cache/` must be writable by PHP; a folder in the system temp directory is used only when it belongs
to your account and nobody else can read it (otherwise the static copies are served).

How it works: `.htaccess` (rule 3b) sends the generated URLs to `api/seo/serve.php`: `/`, `/publications/`,
`/publications/<slug>/` and its `cite.bib`, `/publications/<slug>.md`, `/publications.md`, `/faq/`, `/faq.md`,
`/about/`, `/photos/`, `/blog/`, `/blog/<slug>/`, `/sitemap.xml`, `/llms.txt`, `/llms-full.txt`. Rule 3b is
skipped when `/.seo-static` exists or `api/seo/serve.php` is missing; maintenance mode (503) is checked before
it, and every other file (assets, papers, uploads, images, 404.html, maintenance.html) is served by Apache as
before. `serve.php` answers 200, 301 (trailing slash, or a slug that was changed), 304 or a real 404; a direct
request to `/api/seo/serve.php` is 404, and `api/seo/.htaccess` denies everything else in that folder. It
serves the cached page for the current content version, or renders all pages once (about 100 ms) when the
content changed. It never writes to the database.

Cache folder: `api/cache/seo/` (created by PHP, mode 0755; never served: `api/cache/.htaccess` and
`api/.htaccess` block it). It holds the rendered versions, `errors.log` and `render-failed.json` (a failed
render is not retried for 300 s; visitors get the previous version meanwhile, and the admin switches to a
new version only once it is fully rendered). Deleting the folder is safe: it is rebuilt on the next request.
The admin keeps item dates and the homepage snapshot in the `content` rows `_private_seo` and
`_private_home`; they are not in `ALLOWED_SECTIONS`, so `/api/content.php` never returns them. Do not edit or
delete them by hand. The website build (`dist/_seo/`, never served) brings the page
shells, the shared styles and scripts and `manifest.json`; upload it with the rest of the website.

If the database is down, the pages already in the cache are served. With no cache either, the copies
from the last website build are served (they are still uploaded, as before), and a page added after
that build answers 503 "try again later" (search engines keep it). Invalid content written directly in
phpMyAdmin keeps the last good version online.

Admin → **Site → Public pages & SEO** shows the status, links to all generated files, notes on the
content (for example a paper without an abstract), a **Rebuild cache now** button and an **emergency
switch**: it creates the empty file `/.seo-static`, after which Apache serves the last uploaded build's
copies exactly as before this feature. You can also create that file yourself in File Manager (for
example if pages ever show errors); delete it to go back to live pages. Not uploading `api/seo/` has the
same effect.

`404.html` and `maintenance.html` stay plain files for Apache (unknown addresses and maintenance mode);
the admin rewrites them after each save so their menus and footers match, keeping your maintenance message.

Optional, in `config.php`: `'seo_block_title_sha256' => ['<sha256 of a lower-case title with only a-z0-9>', …]`
refuses to publish papers with those titles (a guard for work under review; the titles never appear on
the server in plain text). The admin checks it on every save of Publications, even when `api/seo/` or the
website build is missing, and `api/content.php` never sends such a paper. Test-only switches (never on
the live site): `seo_test_db_down`, `seo_force_fail`, `seo_debug_header` (adds `X-SEO-Cache: hit|miss|stale|static`
to public pages; off by default because it would show anyone when the database is down).
