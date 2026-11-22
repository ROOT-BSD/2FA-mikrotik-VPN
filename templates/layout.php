<?php
/**
 * Public pages (login, code, result, enrolment). Look of the ITSM System: Bootstrap 5,
 * dark top bar, light background, centred card. Bootstrap is served locally (CSP
 * style-src 'self', no JavaScript).
 *
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
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/app.css">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <span class="navbar-brand">VPN-GW</span>
        <span class="navbar-text text-light small d-none d-sm-inline">Захищений доступ до корпоративної мережі</span>
    </div>
</nav>
<main class="container py-4 flex-grow-1">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
<?= $content ?>
        </div>
    </div>
</main>
<footer class="text-center text-muted small py-3">VPN-GW · двофакторний доступ до VPN</footer>
</body>
</html>
