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

// CSRF protection example — uncomment to enable globally:
// (leading \ is required: this file lives in the global namespace)
// \Widdx\Router::use(\Widdx\CsrfMiddleware::class);

