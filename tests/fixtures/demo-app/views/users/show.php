<!DOCTYPE html>
<html <?= lang_attrs() ?>>
<head><meta charset="utf-8"><title>User: <?= e($user->name) ?></title>
<style>body{font-family:system-ui,Tahoma,sans-serif;max-width:600px;margin:40px auto;padding:0 16px}</style>
</head>
<body>
    <h1><?= e($user->name) ?></h1>
    <p>Email: <?= e($user->email) ?></p>
    <p>
        <a href="/users/<?= e($user->id) ?>/edit">Edit</a> |
        <a href="/users">← List</a>
    </p>
    <form method="POST" action="/users/<?= e($user->id) ?>" onsubmit="return confirm('Delete user?')">
        <input type="hidden" name="_method" value="DELETE">
        <?= csrf_field() ?>
        <button type="submit">Delete</button>
    </form>
</body>
</html>
