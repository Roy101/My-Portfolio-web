# Content Admin (`palashroy.me/admin`) — one-time setup

The admin is **Decap CMS**. It edits your content and **saves to GitHub**; your live cPanel
site updates when you rebuild + upload (see "Publishing" at the bottom).

Because the site is on cPanel (not Netlify), GitHub login needs a tiny **OAuth proxy**.
This is a **one-time** setup.

## 1. Push the repo to GitHub
```
git push origin 2024-portfolio-update
```
(Decap reads/writes the branch set in `public/admin/config.yml` → `branch:`.)

## 2. Register a GitHub OAuth App
GitHub → Settings → Developer settings → **OAuth Apps** → **New OAuth App**
- Application name: `palashroy.me CMS`
- Homepage URL: `https://palashroy.me`
- Authorization callback URL: `https://palashroy-cms-auth.<your-subdomain>.workers.dev/callback`
  (you'll get this exact URL in step 3 — come back and paste it)
- Save, then copy the **Client ID** and generate a **Client secret**.

## 3. Deploy the free OAuth proxy (Cloudflare Worker)
Use the maintained worker **sveltia-cms-auth** (works with Decap):
- Repo: https://github.com/sveltia/sveltia-cms-auth  → "Deploy to Cloudflare"
- In the Worker settings add environment variables:
  - `GITHUB_CLIENT_ID` = (from step 2)
  - `GITHUB_CLIENT_SECRET` = (from step 2)
  - `ALLOWED_DOMAINS` = `palashroy.me`
- Copy the Worker URL, e.g. `https://palashroy-cms-auth.<you>.workers.dev`
- Put that URL as the callback in your GitHub OAuth App (step 2): add `/callback`.

## 4. Point the CMS at the proxy
In `public/admin/config.yml`, set:
```yaml
backend:
  name: github
  repo: Roy101/My-Portfolio-web
  branch: 2024-portfolio-update
  base_url: https://palashroy-cms-auth.<you>.workers.dev
```
Also add that same Worker origin to `connect-src` in `public/admin/.htaccess`.

## 5. Rebuild + upload, then log in
- Rebuild: `~/Desktop/Portfolio/.build-tools/build.sh` (produces the zip).
- Upload the zip to cPanel `public_html` and extract.
- Visit **https://palashroy.me/admin** → "Login with GitHub" → you're in.

---

## Daily use
- Go to `palashroy.me/admin`, edit Publications / Achievements / News / In the News / Pictures.
- Click **Publish** → Decap commits to GitHub.
- To push those changes live on cPanel: run `build.sh`, upload the new zip. (This step is
  manual because cPanel has no auto-build; a host like Netlify would automate it.)

## What's editable in the admin
- **Publications** — papers (title, authors, venue, year, pages, award, DOI, description, PDF)
- **Achievements & Awards**
- **News & Milestones**
- **In the News** (press links)
- **Pictures** — gallery images (upload + caption)

Citation metrics refresh automatically from OpenAlex on every build.
