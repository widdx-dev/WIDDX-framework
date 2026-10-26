# Deploy

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 20 — Deploy

**Server with SSH:** point the web root to `public/`, run `php widdx migrate`, add one cron line for `schedule:run`, set `APP_DEBUG=false`.

**Shared hosting, NO terminal:**
1. Create the project with `php widdx new my-app` (secure default; do NOT use `--demo` for production).
2. Upload the project folder to `public_html/` (zip + extract is fine), or preferably set the document root to `public/` if the host allows it.
3. Create the MySQL database + user in cPanel.
4. Open `https://yoursite/install.php`; provide database settings and the canonical `APP_URL` for this site (use HTTPS on a public site).
5. The installer tests the DB, writes `.env`, runs migrations, and locks itself after success. It refuses to overwrite an existing `.env`.
6. Before launch, review `routes/web.php`, configure authentication and policies, confirm `APP_DEBUG=false`, and test the site over HTTPS.

Notes:
- Composer is NOT required for the framework fallback autoloader, but use Composer and commit `composer.lock` for application dependency reproducibility when possible.
- `storage/` and its subfolders must be writable by PHP but should not be publicly accessible as raw files.
- The root `.htaccess` fallback requires Apache `mod_rewrite` and appropriate `AllowOverride`; test it on the target host. Prefer a document root pointing directly at `public/`.
- Cron: same `php widdx …` commands work from cPanel Cron Jobs.

---
