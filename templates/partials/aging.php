<div class="aging" role="group" aria-label="Balance by age">
  <div><b class="num"><?= $money($aging->current->amount) ?></b><span>0-7 days</span></div>
  <div><b class="num"><?= $money($aging->days8to14->amount) ?></b><span>8-14 days</span></div>
  <div><b class="num"><?= $money($aging->days15to30->amount) ?></b><span>15-30 days</span></div>
  <div><b class="num"><?= $money($aging->over30->amount) ?></b><span>30+ days</span></div>
</div>
