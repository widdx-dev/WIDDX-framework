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
    ],

    'auth' => [
        'failed' => 'These credentials do not match our records.',
        'link_sent' => 'If that email exists, a password reset link has been sent.',
        'password_reset' => 'Password updated. You can log in now.',
        'invalid_token' => 'This reset/verification token is invalid or expired.',
        'verified' => 'Your email address has been verified.',
        'verify_sent' => 'A fresh verification link has been sent to your email.',
    ],
];
