<?php $layout('layouts/owner', ['title' => 'Price board', 'nav' => 'board']); ?>
<div class="row between">
  <div><h1>Today's price board</h1><p class="muted"><?= $e($dateLabel) ?>. Customers see this list on the shop front.</p></div>
  <form method="post" action="/owner/board/copy"><?= $csrf() ?><button class="btn ghost" type="submit">Copy yesterday's list</button></form>
</div>

<?php if ($entries === []): ?>
<section class="card empty">
  <h2>Nothing on today's list yet</h2>
  <p class="muted">Tap "Copy yesterday's list" to start from the last prices, then adjust. Or add items below.</p>
</section>
<?php else: ?>
<form method="post" action="/owner/board" class="stack">
  <?= $csrf() ?>
<?php foreach ($entries as $s): $id = (int) $s->entryId; ?>
  <div class="card flat">
    <div class="row between"><strong><?= $e($s->nameEn) ?> <span class="muted">/ <?= $e($s->nameSw) ?></span></strong>
      <button class="btn sm danger" type="submit" formaction="/owner/board/<?= $e($id) ?>/remove" formnovalidate>Remove</button></div>
    <div class="edit-row">
      <label class="field"><span>Unit</span>
        <select class="input" name="rows[<?= $e($id) ?>][unit]">
<?php foreach ($units as $u): ?><option value="<?= $e($u->value) ?>"<?= $raw($u === $s->unit ? ' selected' : '') ?>><?= $e($u->label()) ?> (<?= $e($u->labelSw()) ?>)</option><?php endforeach; ?>
        </select></label>
      <label class="field"><span>Price (KSh)</span><input class="input num" name="rows[<?= $e($id) ?>][price]" inputmode="numeric" value="<?= $e($s->price->amount) ?>"></label>
      <label class="field"><span>Available</span><input class="input num" name="rows[<?= $e($id) ?>][available]" inputmode="decimal" value="<?= $e($qty($s->available->milli)) ?>"></label>
      <div class="field wide"><span>Special</span>
        <div class="row"><label class="choice"><input type="checkbox" name="rows[<?= $e($id) ?>][special]" value="1"<?= $raw($s->special ? ' checked' : '') ?>> Special</label>
        <input class="input grow" name="rows[<?= $e($id) ?>][note]" maxlength="60" placeholder="Note, e.g. ripe, good for stew" aria-label="Special note" value="<?= $e($s->specialNote) ?>"></div></div>
    </div>
  </div>
<?php endforeach; ?>
  <button class="btn block" type="submit">Save prices and stock</button>
</form>
<?php endif; ?>


<section class="card stack" aria-labelledby="add-h" id="add">
  <h2 id="add-h">Add an item</h2>
  <form method="post" action="/owner/board/add" class="stack">
    <?= $csrf() ?>
<?php if ($available !== []): ?>
    <label class="field"><span>Pick from your item list</span>
      <select class="input" name="item_id">
        <option value="0">New item (fill in the names below)</option>
<?php foreach ($available as $item): ?><option value="<?= $e($item->id) ?>"><?= $e($item->nameEn) ?> / <?= $e($item->nameSw) ?></option><?php endforeach; ?>
      </select></label>
<?php else: ?>
    <input type="hidden" name="item_id" value="0">
<?php endif; ?>
    <details><summary>New item names</summary>
      <div class="edit-row">
        <label class="field"><span>English name</span><input class="input" name="name_en" maxlength="60"></label>
        <label class="field"><span>Swahili name</span><input class="input" name="name_sw" maxlength="60"></label>
        <label class="field wide"><span>Other names customers use <span class="hint">comma separated: helps read WhatsApp orders</span></span><input class="input" name="aliases" maxlength="300"></label>
      </div>
    </details>
    <div class="edit-row">
      <label class="field"><span>Sold per</span>
        <select class="input" name="unit"><?php foreach ($units as $u): ?><option value="<?= $e($u->value) ?>"><?= $e($u->label()) ?> (<?= $e($u->labelSw()) ?>)</option><?php endforeach; ?></select></label>
      <label class="field"><span>Price (KSh)</span><input class="input" name="price" inputmode="numeric" required></label>
      <label class="field"><span>Available</span><input class="input" name="available" inputmode="decimal" value="20"></label>
      <label class="field"><span>Special note <span class="hint">optional</span></span><input class="input" name="note" maxlength="60"></label>
    </div>
    <button class="btn" type="submit">Add to today's list</button>
  </form>
</section>

<section class="card stack" aria-labelledby="slots-h">
  <h2 id="slots-h">Delivery slots today</h2>
  <p class="muted">How many orders each window can take. A full slot closes on the shop front.</p>
  <form method="post" action="/owner/board/slots" class="stack">
    <?= $csrf() ?>
    <div class="edit-row">
<?php foreach ($slots as $slot): ?>
      <label class="field"><span><?= $e($slot->label()) ?> <span class="hint"><?= $e($booked[(int) $slot->id] ?? 0) ?> booked</span></span><input class="input num" name="cap[<?= $e($slot->id) ?>]" inputmode="numeric" value="<?= $e($slot->capacity) ?>"></label>
<?php endforeach; ?>
    </div>
    <button class="btn ghost" type="submit">Save slot sizes</button>
  </form>
</section>
<p><a href="/owner/items">Edit item names and aliases</a></p>
