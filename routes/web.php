<?php

// Routes file — write minimal code, the framework handles the rest.

use App\Controllers\UserController;

// Home page
get('/', fn() => view('welcome'));

// One line = full CRUD (index, create, store, show, edit, update, destroy)
// with named routes: users.index, users.create, users.store, ...
// ->public() opens the group to guests; without it (and without a
// App\Policies\UserPolicy) the fail-closed gate redirects/403s every call.
resource('users', UserController::class)->public();

// Middleware example — uncomment to try:
// (\Widdx\AuthMiddleware guards for real: guests → 302 /login, JSON → 401.
//  App\Middleware\AuthMiddleware simply extends it for project-specific rules.)
// get('/admin', fn() => 'Admin panel')->middleware(\Widdx\AuthMiddleware::class);

// CSRF + security headers are ALREADY on: core/App.php puts them in the ambient
// 'web' group, which covers every route in this file. Do not add them again here —
// Router::use() would run them a second time. Use it only to REPLACE a group:
// \Widdx\Router::middlewareGroup('web', [\Widdx\CsrfMiddleware::class]);

