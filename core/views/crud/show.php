<?php
/** Zero-code detail view. @var mixed $item @var array $columns @var string $routeBase @var string $title */
include __DIR__ . '/layout-top.php';
?>
<div class="card">
<h1><?= e($title) ?> #<?= e((string) $item->id) ?></h1>
<table>
<?php foreach (($columns ?? []) as $c): ?>
<tr><th style="width:35%"><?= e(ucwords(str_replace('_', ' ', $c))) ?></th><td><?= nl2br(e((string) ($item->{$c} ?? ''))) ?></td></tr>
<?php endforeach; ?>
<tr><th>Created</th><td><?= e((string) ($item->created_at ?? '—')) ?></td></tr>
</table>
<p style="margin-top:16px">
<a class="btn" href="<?= e($routeBase) ?>/<?= e((string) $item->id) ?>/edit">Edit</a>
<a class="btn secondary" href="<?= e($routeBase) ?>">Back to list</a>
</p>
</div>
<?php include __DIR__ . '/layout-bottom.php'; ?>
