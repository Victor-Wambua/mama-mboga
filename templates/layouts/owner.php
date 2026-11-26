<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?> · <?= $e($shopName) ?> owner</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1d5e3a">
<meta name="csrf-token" content="<?= $e($csrfToken) ?>">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
  <div class="wrap wide">
    <a class="brand" href="/owner"><?= $partial('partials/logo') ?><span><?= $e($shopName) ?></span></a>
    <span class="sp"></span>
    <a class="link hide-sm" href="/">Shop front</a>
    <form method="post" action="/owner/logout"><?= $csrf() ?><button class="link" type="submit">Sign out</button></form>
  </div>
</header>
<nav class="tabs" aria-label="Owner console">
  <div class="wrap wide"><ul>
<?php
$tabs = ['orders' => ['/owner', 'Orders'], 'board' => ['/owner/board', 'Price board'], 'paste' => ['/owner/paste', 'Paste order'], 'shopping' => ['/owner/shopping', 'Market list'], 'run' => ['/owner/run-sheet', 'Run sheet'], 'takings' => ['/owner/takings', 'Takings'], 'deni' => ['/owner/deni', 'Deni'], 'settings' => ['/owner/settings', 'Settings']];
foreach ($tabs as $key => [$href, $label]): ?>
    <li><a href="<?= $e($href) ?>"<?= $raw(($nav ?? '') === $key ? ' aria-current="page"' : '') ?>><?= $e($label) ?></a></li>
<?php endforeach; ?>
  </ul></div>
</nav>
<main id="main" class="wrap wide">
<?php foreach ($flash as $f): ?>
  <div class="flash <?= $e($f['type']) ?>" role="<?= $e($f['type'] === 'error' ? 'alert' : 'status') ?>"><?= $e($f['message']) ?></div>
<?php endforeach; ?>
<?= $raw($content) ?>
</main>
<script src="/assets/js/pwa.js" defer></script>
<?php if (!empty($script)): ?><script src="/assets/js/<?= $e($script) ?>.js" defer></script><?php endif; ?>
</body>
</html>
