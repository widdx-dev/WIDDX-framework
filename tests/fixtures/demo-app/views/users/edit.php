<!DOCTYPE html>
<html <?= lang_attrs() ?>>
<head><meta charset="utf-8"><title>Edit: <?= e($user->name) ?></title>
<style>body{font-family:system-ui,Tahoma,sans-serif;max-width:500px;margin:40px auto;padding:0 16px}input{display:block;width:100%;margin:8px 0 16px;padding:10px;border:1px solid #cbd5e1;border-radius:8px}button{background:#4f46e5;color:#fff;padding:10px 24px;border:none;border-radius:8px}.errors{background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:16px}</style>
</head>
<body>
    <h1>Edit User</h1>
    <?php if (!empty(errors())): ?>
        <div class="errors">
            <ul>
                <?php foreach (errors() as $field => $messages): ?>
                    <?php foreach ((array) $messages as $message): ?>
                        <li><?= e($message) ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="POST" action="/users/<?= e($user->id) ?>">
        <input type="hidden" name="_method" value="PUT">
        <?= csrf_field() ?>
        <label>Name<input type="text" name="name" value="<?= e(old('name', $user->name)) ?>" required></label>
        <label>Email<input type="email" name="email" value="<?= e(old('email', $user->email)) ?>" required></label>
        <button type="submit">Update</button>
    </form>
    <p><a href="/users">← List</a></p>
</body>
</html>
