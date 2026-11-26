<?php $layout('layouts/public', ['title' => $title, 'noindex' => true]); ?>
<section class="empty card" role="alert">
  <p class="muted"><?= $e($status) ?></p>
  <h1><?= $e($title) ?></h1>
  <p><?= $e($text) ?></p>
<?php if ($detail !== ''): ?><p class="small muted"><?= $e($detail) ?></p><?php endif; ?>
  <p><a class="btn" href="/">Back to today's prices</a></p>
</section>
