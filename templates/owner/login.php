<?php $layout('layouts/public', ['title' => 'Owner sign in', 'noindex' => true]); ?>
<section class="login card stack">
  <h1>Owner sign in</h1>
<?php if (!$configured): ?>
  <div class="flash error" role="alert">No PIN is set yet. Run <code>composer seed</code> for the demo, or <code>php bin/console owner:pin 123456</code>.</div>
<?php endif; ?>
<?php if ($failed): ?>
  <div class="flash error" role="alert">That PIN or password is not right.</div>
<?php endif; ?>
  <form method="post" action="/owner/login" class="stack">
    <?= $csrf() ?>
    <input type="hidden" name="next" value="<?= $e($next) ?>">
    <div class="field">
      <label for="pin">PIN or password</label>
      <input class="input" id="pin" name="pin" type="password" autocomplete="current-password" inputmode="text" required autofocus>
    </div>
    <button class="btn block" type="submit">Sign in</button>
  </form>
  <p class="small muted">Too many wrong tries and sign-in pauses for a while.</p>
</section>
