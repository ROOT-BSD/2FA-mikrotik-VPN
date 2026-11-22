<?php
/**
 * @var callable(mixed): string $e
 * @var string $base
 * @var string $heading
 * @var string $text
 * @var string|null $kind  success|warning|error
 */
$kind ??= 'info';
$bs = ['success' => 'success', 'warning' => 'warning', 'error' => 'danger'][$kind] ?? 'info';
?>
<h3 class="mb-4 text-center"><?= $e($heading) ?></h3>
<div class="alert alert-<?= $e($bs) ?>"><?= $e($text) ?></div>
<a class="btn btn-primary w-100" href="<?= $e($base) ?>/">На початок</a>
