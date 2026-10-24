# Changelog

## Unreleased

### Security

- API routes now apply CSRF validation and security headers by default. Requests carrying the PHP session cookie must provide a matching token for write methods; stateless requests without that cookie remain exempt. Clients can send `X-CSRF-TOKEN`, `csrf_token`, or `_token`. Projects that override the `api` middleware group replace these defaults and must opt in to the middleware they need.
- Migration note: browser-based API clients that send the session cookie must obtain a token (for example with a GET route returning `csrf_token()`) and include it on POST, PUT, PATCH, and DELETE requests. Stateless API integrations without a session cookie do not need to change.
