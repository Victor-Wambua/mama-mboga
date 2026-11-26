<?php $layout('layouts/owner', ['title' => 'Items', 'nav' => 'board']); ?>
<p><a href="/owner/board">&larr; Price board</a></p>
<h1>Item names</h1>
<p class="muted">The names and spellings customers use. The WhatsApp reader matches messages against these, so add every nickname you hear ("sukuma", "kales", "hoho").</p>
<div class="stack">
<?php foreach ($items as $item): ?>
  <form method="post" action="/owner/items/<?= $e($item->id) ?>" class="card flat stack">
    <?= $csrf() ?>
    <div class="edit-row">
      <label class="field"><span>English</span><input class="input" name="name_en" maxlength="60" value="<?= $e($item->nameEn) ?>"></label>
      <label class="field"><span>Swahili</span><input class="input" name="name_sw" maxlength="60" value="<?= $e($item->nameSw) ?>"></label>
      <label class="field"><span>Usually sold per</span>
        <select class="input" name="unit"><?php foreach ($units as $u): ?><option value="<?= $e($u->value) ?>"<?= $raw($u === $item->defaultUnit ? ' selected' : '') ?>><?= $e($u->label()) ?></option><?php endforeach; ?></select></label>
      <label class="field wide"><span>Other names</span><input class="input" name="aliases" maxlength="300" value="<?= $e(implode(', ', $item->aliases)) ?>"></label>
    </div>
    <button class="btn sm ghost" type="submit">Save</button>
  </form>
<?php endforeach; ?>
</div>
