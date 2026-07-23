# Deploy Guide — palashroy.me

## The mental model (what connects to what)

```
   GitHub repo            Build (on your computer)         cPanel hosting
 (your code + content)  ───────────────────────────►   (your live website)
        ▲                    build.sh makes a zip            visitors here
        │
     /admin  ──── you edit content here (saves to GitHub)
```

- **GitHub** = filing cabinet for your code + content. It does NOT host your site.
- **cPanel** = your live website (the storefront). Visitors always hit this.
- **/admin** = the editor. It saves changes to GitHub. To make them live you rebuild + upload to cPanel.

The finished upload file is: `~/Desktop/Portfolio/palashroy-cpanel.zip`

---

## PART 1 — Put the site live on cPanel (do this now)

1. Log in to **cPanel** (from your hosting provider).
2. Open **File Manager**.
3. Go to the folder your domain serves — usually **`public_html`** (for the main domain).
4. *(Recommended)* Select the old site files and **delete** them (or move to a backup folder) so nothing stale remains.
5. Click **Upload**, choose **`palashroy-cpanel.zip`**, wait for 100%.
6. Back in File Manager, **right-click the zip → Extract** (extract into `public_html`).
7. Delete the `palashroy-cpanel.zip` from the server after extracting.
8. In File Manager **Settings** (top-right), tick **Show Hidden Files (dotfiles)**, and confirm these exist:
   - `public_html/.htaccess`  (site rules: HTTPS, security, caching)
   - `public_html/admin/.htaccess`  (special rules just for the CMS)
9. Visit **https://palashroy.me** and hard-refresh (Ctrl+Shift+R). Done — the site is live.

That's the ONLY step needed to have the website live. Everything below is for the editing panel.

---

## PART 2 — Push your code to GitHub (needed only for the /admin editor)

Your live site does not need GitHub. But the `/admin` editor saves there, so:

```
cd ~/Desktop/Portfolio/Palash-Ranjan-Roy-Portfolio
git push origin 2024-portfolio-update
```

(If it asks for login, use your GitHub username + a Personal Access Token, or push from the
VS Code Source Control panel.)

---

## PART 3 — Turn on the /admin editor (one-time, ~15 min)

Full steps are in **`ADMIN-SETUP.md`**. Short version:

1. **GitHub → Settings → Developer settings → OAuth Apps → New OAuth App**
   - Homepage: `https://palashroy.me`
   - Callback: (you get this URL in step 2) + `/callback`
   - Copy the **Client ID** and a new **Client secret**.
2. **Deploy the free OAuth proxy** (Cloudflare Worker `sveltia-cms-auth`) → set `GITHUB_CLIENT_ID`,
   `GITHUB_CLIENT_SECRET`, `ALLOWED_DOMAINS=palashroy.me`. Copy its URL.
3. Put that URL into `public/admin/config.yml` → `base_url:` (and in `public/admin/.htaccess` connect-src).
4. Rebuild + re-upload (Part 4), then open **https://palashroy.me/admin** → Login with GitHub.

You only do Part 3 once.

---

## PART 4 — Everyday workflow (after a change)

Whenever you (or the CMS) change content:

1. **Rebuild** — run one command on your computer:
   ```
   ~/Desktop/Portfolio/.build-tools/build.sh
   ```
   This refreshes your citation metrics from OpenAlex, builds the site, prerenders it for SEO,
   and produces a fresh `~/Desktop/Portfolio/palashroy-cpanel.zip`.
2. **Upload** — repeat Part 1, steps 5–7 (upload the new zip to `public_html`, extract, delete zip).

That's it. (A host like Netlify would do steps 1–2 automatically on save — on cPanel it's manual.)

**Simplest option:** just tell me what changed and I'll run the build and hand you the new zip.

---

## Where everything lives
- Live site upload file: `~/Desktop/Portfolio/palashroy-cpanel.zip`
- One-command rebuild: `~/Desktop/Portfolio/.build-tools/build.sh`
- Editable content: `palash-portfolio/src/content/*.json` (also editable in /admin)
- CMS config: `palash-portfolio/public/admin/config.yml`
- OAuth setup: `ADMIN-SETUP.md`

## What you can edit in /admin
Publications · Achievements & Awards · News & Milestones · In the News (press) ·
Pictures (with image upload) · Leadership · Academic Service · References.
Citation metrics update automatically on every build.
