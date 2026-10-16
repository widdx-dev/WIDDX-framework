# Deploy

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 20 — Deploy

**Server with SSH:** point the web root to `public/`, run `php widdx migrate`, add one cron line for `schedule:run`, set `APP_DEBUG=false`.

**Shared hosting, NO terminal:**
1. Upload the project folder to `public_html/` (zip + extract is fine).
2. Create the MySQL database + user in cPanel.
3. Open `https://yoursite/install.php`.
4. Fill the form. It tests the DB first, writes `.env`, runs migrations, then locks forever.
5. `install.php` now returns 404. It can never overwrite your `.env`. That is security, not a bug.

Notes:
- Composer NOT required (fallback autoloader ships in `public/index.php`).
- `storage/` and its 4 subfolders must be writable (usually 755, sometimes 775).
- Cron: same `php widdx …` commands work from cPanel Cron Jobs.

---
