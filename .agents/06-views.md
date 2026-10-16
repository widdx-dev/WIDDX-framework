# Views and templates

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 12 — Views (templates)

- `view('welcome')` renders `views/welcome.php`.
- `view('admin.panel')` renders `views/admin/panel.php`.
- Pass data: `return view('post', ['title' => $t]);` — then use `$title` in the file.
- Return it from a route or controller. Strings/arrays return directly too.
- ALWAYS escape: `<?= e($title) ?>`.
- Available helpers in views: `e()` `csrf_field()` `old()` `errors()` `session()` `auth()` `lang_attrs()`.
- Remember: views are GLOBAL namespace. `use` statements do not exist there. Class calls fail.
- Built-in pages live in `core/views/` (crud + auth). Copy one into `views/<name>/` to restyle it. Your file wins automatically.

---
