# Validation and file uploads

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 11 — Validation

One call checks everything and redirects back with the errors shown:

```
request()->validate(['email' => 'required|email|unique:users,email']);
```

Models do it automatically inside `create()` and `update()` using `$rules`.

Every rule, one line each:

- `required` — must be present and not empty.
- `nullable` — may be absent; other rules skipped when empty.
- `string` `int` `float` `bool` `numeric` `array` — type checks.
- `email` `url` — format checks.
- `min:5` `max:255` — length for text, value for numbers, KILOBYTES for files.
- `in:a,b,c` — must be one of the listed.
- `confirmed` — needs a matching `field_confirmation` input (passwords).
- `same:other` / `different:other` — compare two fields.
- `exists:users,id` — value must be a real row (used by fk dropdowns).
- `unique:users,email` — not taken. Optional 3rd part: `unique:users,email,5` ignores id 5.
- `file` — a real uploaded file.
- `image` — an uploaded image.
- `mimes:jpg,png` — uploaded extension check.

Important upload fact: `file/image/mimes` also PASS when the value is a stored path string.
Reason: update forms re-validate old records that have no new file. Do not change this.

Error output: HTML forms get redirected back; use `errors()` and `old('field')` in views.
Custom message: `validate($rules, ['email.required' => 'Email needed!'])`.
Default messages are translated by `APP_LOCALE` (en, ar included).

---

## PART 13 — File uploads

You add ONE rule and get the whole feature:

```php
protected array $rules = ['cover' => 'nullable|image|mimes:jpg,png|max:2048'];
```

- The built-in form shows a file input (because the rule says `image`).
- On submit, the file is stored under `storage/app/` and the DB column keeps the path.
- Reading it yourself: `$f = request()->file('cover'); $f->store('covers');`
- `$f->originalName() $f->extension() $f->mimeType() $f->isImage() $f->sizeKb()` all exist.
- Editing a record and submitting WITHOUT a new file keeps the old path. Intended.

---
