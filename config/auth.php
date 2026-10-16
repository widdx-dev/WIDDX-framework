<?php
// Auth configuration
return [
    // User model class
    'model' => 'App\\Models\\User',
    // The session keys Auth writes ('auth_user_id', 'auth_user_class') are
    // internal, not settings: a per-project key would need every reader
    // (Auth, middleware, views, the built-in auth views) to consult config.
];
