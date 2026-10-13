<?php

// Routes file — write minimal code, the framework handles the rest.

use App\Controllers\UserController;

// Home page
get('/', fn() => view('welcome'));

// One line = full CRUD (index, create, store, show, edit, update, destroy)
// with named routes: users.index, users.create, users.store, ...
resource('users', UserController::class);

// Middleware example — uncomment to try:
// get('/admin', fn() => 'Admin panel')->middleware(App\Middleware\AuthMiddleware::class);

// CSRF protection example — uncomment to enable globally:
// (leading \ is required: this file lives in the global namespace)
// \Widdx\Router::use(\Widdx\CsrfMiddleware::class);

