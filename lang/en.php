<?php

/**
 * English framework messages (default locale — the fallback for all others).
 * Placeholders use the :name syntax and are replaced at runtime.
 */
return [
    'validation' => [
        'required' => 'The :field field is required.',
        'string' => 'The :field must be a string.',
        'int' => 'The :field must be an integer.',
        'float' => 'The :field must be a number.',
        'bool' => 'The :field must be a boolean.',
        'email' => 'The :field must be a valid email.',
        'url' => 'The :field must be a valid URL.',
        'numeric' => 'The :field must be numeric.',
        'array' => 'The :field must be an array.',
        'min.string' => 'The :field must be at least :min characters.',
        'min.numeric' => 'The :field must be at least :min.',
        'min.array' => 'The :field must have at least :min items.',
        'max.string' => 'The :field must not exceed :max characters.',
        'max.numeric' => 'The :field must not exceed :max.',
        'max.array' => 'The :field must not have more than :max items.',
        'in' => 'The :field must be one of: :values.',
        'confirmed' => 'The :field confirmation does not match.',
        'same' => 'The :field and :other must match.',
        'different' => 'The :field and :other must be different.',
        'exists' => 'The selected :field is invalid.',
        'unique' => 'The :field has already been taken.',
        'file' => 'The :field must be a file.',
        'image' => 'The :field must be an image.',
        'mimes' => 'The :field must be a file of type: :values.',
        'max.file' => 'The :field may not be greater than :max kilobytes.',
        'min.file' => 'The :field must be at least :min kilobytes.',
        'date' => 'The :field must be a valid date.',
        'alpha' => 'The :field may only contain letters.',
        'alpha_num' => 'The :field may only contain letters and numbers.',
        'digits' => 'The :field must be :digits digits.',
        'json' => 'The :field must be a valid JSON string.',
        'regex' => 'The :field format is invalid.',
        'starts_with' => 'The :field must start with: :values.',
        'ends_with' => 'The :field must end with: :values.',
        'size' => 'The :field must be :size.',
        'between' => 'The :field must be between :min and :max.',
    ],

    'auth' => [
        'failed' => 'These credentials do not match our records.',
        'link_sent' => 'If that email exists, a password reset link has been sent.',
        'password_reset' => 'Password updated. You can log in now.',
        'invalid_token' => 'This reset/verification token is invalid or expired.',
        'verified' => 'Your email address has been verified.',
        'verify_sent' => 'A fresh verification link has been sent to your email.',
    ],

    'hints' => [
        'route_not_found' => 'This URL is not registered. Open <code>routes/web.php</code> and add the required route.',
        'view_not_found' => 'The view template does not exist. Check the name passed to <code>view()</code> and verify the file exists in <code>views/</code>.',
        'binding' => 'Automatic dependency injection failed. Check the details below — likely a parameter name that does not match <code>{param}</code> in the route, or a missing class.',
        'database' => 'Database problem. Check <code>config/database.php</code> and verify the required table exists.',
        'not_found' => 'The requested item does not exist. Check the ID passed in the URL.',
        'method_not_allowed' => 'The URL exists but the HTTP method is wrong. Check the form <code>method</code> (use <code>_method</code> for PUT/DELETE) or the API request method.',
        'validation' => 'Some inputs are invalid. Check the validation errors below.',
        'generic' => 'An unexpected error occurred. Check the details below or the error log.',
    ],
];
