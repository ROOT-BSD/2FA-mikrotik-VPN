<?php
/**
 * @var callable(mixed): string $e
 * @var string $title
 * @var string $base
 * @var string $content  already-rendered, escaped page body
 */
?><!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= $e($title) ?></title>
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/app.css">
</head>
<body>
<main class="card">
    <header class="brand">
        <span class="logo" aria-hidden="true">V</span>
        <span class="brand-name">VPN-GW</span>
    </header>
<?= $content ?>
</main>
<footer class="foot">Захищений доступ до корпоративної мережі</footer>
</body>
</html>
