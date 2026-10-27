<!DOCTYPE html>
<html <?= lang_attrs() ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(config('app.name', 'Widdx')) ?></title>
    <style>
        body { font-family: system-ui, Tahoma, sans-serif; background: #f8fafc; margin: 0; padding: 60px 20px; text-align: center; color: #1e293b; }
        .box { max-width: 640px; margin: auto; background: #fff; padding: 48px; border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,.08); }
        h1 { font-size: 2.5rem; margin: 0 0 8px; }
        code { background: #f1f5f9; padding: 2px 8px; border-radius: 6px; }
        a.btn { display: inline-block; margin-top: 20px; background: #4f46e5; color: #fff; padding: 12px 28px; border-radius: 10px; text-decoration: none; }
    </style>
</head>
<body>
<div class="box">
    <h1>🚀 Welcome to Widdx!</h1>
    <p>A simple PHP framework — inspired by Laravel but much simpler.</p>
    <p>Start from <code>routes/web.php</code> and add your routes.</p>
    <a class="btn" href="/users">View Users</a>
</div>
</body>
</html>
