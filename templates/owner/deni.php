<?php $layout('layouts/owner', ['title' => 'Deni', 'nav' => 'deni']); ?>
<div class="row between">
  <div><h1>Deni book</h1><p class="muted">Who owes what, and how old it is.</p></div>
  <div class="kpi hi"><b class="num"><?= $money($outstanding->amount) ?></b><span>Owed in total</span></div>
</div>
<?php if ($rows === []): ?>
<section class="card empty"><h2>No credit customers yet</h2><p class="muted">Add a regular customer below and give them a credit limit.</p></section>
<?php else: ?>
<div class="card"><div class="table-wrap"><table>
  <thead><tr><th>Customer</th><th class="r">Owes</th><th class="r">Limit</th><th>Oldest</th></tr></thead>
  <tbody>
<?php foreach ($rows as $row): $c = $row['customer']; ?>
    <tr>
      <td><a href="/owner/deni/<?= $e($c->id) ?>"><strong><?= $e($c->name) ?></strong></a><br><span class="small muted num"><?= $e($c->phone->local()) ?></span></td>
      <td class="r num"><strong><?= $money($row['balance']->amount) ?></strong></td>
      <td class="r num muted"><?= $money($c->creditLimit->amount) ?></td>
      <td><?php $bucket = $row['aging']->oldestBucket(); ?><span class="tag<?= $when($bucket === '30+' || $bucket === '15-30', ' low') ?>"><?= $e($bucket === null ? 'Clear' : $bucket . ' days') ?></span></td>
    </tr>
<?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>

<section class="card stack" aria-labelledby="add-h">
  <h2 id="add-h">Add a customer</h2>
  <form method="post" action="/owner/deni/customers" class="stack">
    <?= $csrf() ?>
    <div class="edit-row">
      <label class="field"><span>Name</span><input class="input" name="name" maxlength="80" required></label>
      <label class="field"><span>Phone</span><input class="input" name="phone" type="tel" placeholder="0700 000 001" required></label>
      <label class="field"><span>Credit limit (KSh)</span><input class="input num" name="limit" inputmode="numeric" value="3000"></label>
    </div>
    <button class="btn" type="submit">Add customer</button>
  </form>
</section>
