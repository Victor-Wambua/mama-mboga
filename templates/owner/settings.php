<?php $layout('layouts/owner', ['title' => 'Settings', 'nav' => 'settings']); ?>
<h1>Settings</h1>
<form method="post" action="/owner/settings" class="card stack">
  <?= $csrf() ?>
  <h2>Shop</h2>
  <label class="field"><span>Shop name</span><input class="input" name="name" maxlength="60" value="<?= $e($name) ?>" required></label>
  <label class="field"><span>Tagline <span class="hint">shown on the menu</span></span><input class="input" name="tagline" maxlength="120" value="<?= $e($tagline) ?>"></label>
  <label class="field"><span>Shop WhatsApp number <span class="hint">customers' orders are sent here</span></span><input class="input" name="whatsapp" type="tel" value="<?= $e($whatsapp) ?>" required></label>
  <label class="field"><span>Orders per delivery slot <span class="hint">used for new days</span></span><input class="input num" name="capacity" inputmode="numeric" value="<?= $e($capacity) ?>"></label>
  <button class="btn" type="submit">Save settings</button>
</form>
<form method="post" action="/owner/settings/pin" class="card stack">
  <?= $csrf() ?>
  <h2>Change PIN or password</h2>
  <label class="field"><span>Current</span><input class="input" type="password" name="current_pin" autocomplete="current-password" required></label>
  <label class="field"><span>New <span class="hint">6 or more characters</span></span><input class="input" type="password" name="new_pin" autocomplete="new-password" minlength="6" required></label>
  <label class="field"><span>New again</span><input class="input" type="password" name="confirm_pin" autocomplete="new-password" minlength="6" required></label>
  <button class="btn ghost" type="submit">Change PIN</button>
</form>
