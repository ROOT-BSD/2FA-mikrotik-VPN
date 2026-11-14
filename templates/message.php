<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $heading
 * @var string $text
 * @var string|null $kind  success|warning|error
 */
$kind ??= 'info';
?>
<h1><?= $e($heading) ?></h1>
<div class="alert alert-<?= $e(in_array($kind, ['success', 'warning', 'error'], true) ? $kind : 'info') ?>"><?= $e($text) ?></div>
<p><a class="button-link" href="<?= $e($base) ?>/">На початок</a></p>
