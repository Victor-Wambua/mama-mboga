<?php $layout('layouts/owner', ['title' => 'Paste order', 'nav' => 'paste']); ?>
<h1>Paste a WhatsApp order</h1>
<p class="muted">Copy the customer's message from WhatsApp and paste it here. We match it to today's price board, and flag anything we are not sure about so you can fix it before saving.</p>
<?php if (!$hasBoard): ?>
<div class="flash error">Today's price board is empty, so there is nothing to match against. <a href="/owner/board">Set today's prices</a> first.</div>
<?php endif; ?>
<form method="post" action="/owner/paste" class="card stack">
  <?= $csrf() ?>
  <label class="field"><span>Message</span>
    <textarea class="input" name="message" rows="8" maxlength="4000" placeholder="sukuma 2 bunch, nyanya kilo moja na nusu, 2 ndizi&#10;deliver 6pm" required><?= $e($message) ?></textarea></label>
  <button class="btn" type="submit">Read the order</button>
</form>
<p class="small muted">Understands English and Swahili: "nyanya 1kg", "2 bunch sukuma", "kilo mbili za vitunguu", "nusu kilo", "kumi na mbili ndizi". It never saves anything until you press confirm.</p>
