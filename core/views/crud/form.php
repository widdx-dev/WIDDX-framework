<?php
/** Shared form for create/edit. @var array $fields @var string $routeBase @var mixed $item @var string $title */
$editing = !empty($item);
$action = e($routeBase) . ($editing ? '/' . e((string) $item->id) : '');
$hasFile = false;
foreach ($fields as $f) {
    if ($f['type'] === 'file') {
        $hasFile = true;
    }
}
include __DIR__ . '/layout-top.php';
?>
<div class="card">
<h1><?= $editing ? 'Edit ' . e($title) : 'New ' . e($title) ?></h1>
<?php foreach (errors() as $field => $messages): ?>
    <?php foreach ($messages as $m): ?><div class="error"><?= e($m) ?></div><?php endforeach; ?>
<?php endforeach; ?>
<form method="POST" action="<?= $action ?>"<?= $hasFile ? ' enctype="multipart/form-data"' : '' ?>>
<?= csrf_field() ?>
<?php if ($editing): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>
<?php foreach ($fields as $f): ?>
    <label for="f_<?= e($f['name']) ?>"><?= e($f['label']) ?><?= $f['required'] ? ' *' : '' ?></label>
    <?php $current = $f['type'] === 'password' ? '' : old($f['name'], $f['value'] ?? null); ?>
    <?php if ($f['type'] === 'textarea'): ?>
        <textarea id="f_<?= e($f['name']) ?>" name="<?= e($f['name']) ?>" rows="4"><?= e((string) ($current ?? '')) ?></textarea>
    <?php elseif ($f['type'] === 'select'): ?>
        <select id="f_<?= e($f['name']) ?>" name="<?= e($f['name']) ?>" <?= $f['required'] ? 'required' : '' ?>>
            <?php if (!$f['required']): ?><option value="">—</option><?php endif; ?>
            <?php foreach ($f['options'] as $opt):
                $ov = (string) (is_array($opt) ? ($opt['value'] ?? '') : $opt);
                $ol = (string) (is_array($opt) ? ($opt['label'] ?? $ov) : $opt); ?>
            <option value="<?= e($ov) ?>" <?= (string) $current === $ov ? 'selected' : '' ?>><?= e($ol) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($f['type'] === 'checkbox'): ?>
        <!-- an unchecked box submits nothing at all, so the old value would
             survive the save; the hidden 0 (overridden by the box per pitfall 7)
             is what makes "uncheck and save" work. -->
        <input type="hidden" name="<?= e($f['name']) ?>" value="0">
        <input type="checkbox" id="f_<?= e($f['name']) ?>" name="<?= e($f['name']) ?>" value="1" <?= $current ? 'checked' : '' ?>>
    <?php elseif ($f['type'] === 'file'): ?>
        <input type="file" id="f_<?= e($f['name']) ?>" name="<?= e($f['name']) ?>" <?= $f['required'] ? 'required' : '' ?>>
        <?php if (!empty($f['value'])): ?>
            <small style="color:#64748b">current: <?= e((string) $f['value']) ?> (uploading a new file replaces it)</small>
        <?php endif; ?>
    <?php else: ?>
        <input type="<?= e($f['type']) ?>" id="f_<?= e($f['name']) ?>" name="<?= e($f['name']) ?>"
               value="<?= e((string) ($current ?? '')) ?>" <?= $f['required'] ? 'required' : '' ?><?= ($f['step'] ?? null) !== null ? ' step="' . e((string) $f['step']) . '"' : '' ?>>
    <?php endif; ?>
<?php endforeach; ?>
<p style="margin-top:18px">
<button class="btn" type="submit"><?= $editing ? 'Update' : 'Create' ?></button>
<a class="btn secondary" href="<?= e($routeBase) ?>">Cancel</a>
</p>
</form>
</div>
<?php include __DIR__ . '/layout-bottom.php'; ?>
