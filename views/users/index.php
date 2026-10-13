<!DOCTYPE html>
<html <?= lang_attrs() ?>>
<head><meta charset="utf-8"><title>Users</title>
<style>body{font-family:system-ui,Tahoma,sans-serif;max-width:700px;margin:40px auto;padding:0 16px}li{margin:6px 0}a{color:#4f46e5}.btn{display:inline-block;background:#4f46e5;color:#fff;padding:8px 18px;border-radius:8px;text-decoration:none;margin-bottom:16px}</style>
</head>
<body>
    <h1>User List</h1>
    <a class="btn" href="/users/create">+ New User</a>
    <ul>
        <?php foreach ($users as $user): ?>
            <li>
                <a href="/users/<?= e($user->id) ?>"><?= e($user->name) ?></a>
                - <?= e($user->email) ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (empty($users)): ?>
        <p>No users yet. <a href="/users/create">Add the first user</a></p>
    <?php endif; ?>
    <p><a href="/">← Home</a></p>
</body>
</html>
