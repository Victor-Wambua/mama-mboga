<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e(($title ?? '') !== '' ? $title . ' · ' . $shopName : $shopName) ?></title>
<meta name="description" content="<?= $e($shopTagline) ?>">
<meta name="theme-color" content="#1d5e3a">
<meta name="csrf-token" content="<?= $e($csrfToken) ?>">
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icon-192.png">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<div class="offline-banner" id="offline-banner" role="status" hidden>You are offline. Showing the last price list we saved.</div>
<header class="topbar">
  <div class="wrap">
    <a class="brand" href="/"><?= $partial('partials/logo') ?><span><?= $e($shopName) ?></span></a>
    <span class="sp"></span>
    <?php if ($isOwner): ?><a class="link" href="/owner">Owner console</a><?php endif; ?>
  </div>
</header>
<main id="main" class="wrap">
<?php foreach ($flash as $f): ?>
  <div class="flash <?= $e($f['type']) ?>" role="status"><?= $e($f['message']) ?></div>
<?php endforeach; ?>
<?= $raw($content) ?>
</main>
<footer class="site wrap">
  <p>Demo shop with made-up sample data. <a href="/owner">Owner sign in</a></p>
</footer>
<script src="/assets/js/pwa.js" defer></script>
<?php if (!empty($script)): ?><script src="/assets/js/<?= $e($script) ?>.js" defer></script><?php endif; ?>
</body>
</html>
