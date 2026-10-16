# What Widdx is

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 1 — What is Widdx

- Widdx is a PHP framework that lives inside your project folder.
- Your project IS this folder. There is nothing to install.
- PHP version needed: 8.1 or newer.
- Composer is optional. The autoloader works without it.
- There is no npm. There is no build step. There is no config file to edit.
- The idea of Widdx: you write almost nothing. The framework infers the rest from names.
- One line `resource('posts')->public()` = a full working CRUD website for posts.
- One line `authRoutes()` = register + login + logout + password reset + email verify.
- One command `make:crud` = model + database table + routes, all at once.
- Version: v0.3.7. All behavior below is tested (the unit suite + the integration suite; the runner prints its own totals).

---
