// Menu page: + / - steppers, live total, sticky basket bar, and showing the
// address and slot fields only for delivery. Without JS the number inputs
// and the full form still work; the server recalculates everything.
(() => {
  const form = document.querySelector('form[data-basket]');
  if (!form) return;

  const inputs = [...form.querySelectorAll('.qty-input')];
  const bar = document.getElementById('basket-bar');
  const barTotal = document.getElementById('basket-total');
  const barCount = document.getElementById('basket-count');
  const formTotal = document.getElementById('form-total');
  const money = (kes) => 'KSh ' + kes.toLocaleString('en-KE');
  const toMilli = (value) => Math.round((parseFloat(String(value).replace(',', '.')) || 0) * 1000);
  const fromMilli = (milli) => String(milli / 1000);

  const update = () => {
    let total = 0;
    let lines = 0;
    for (const input of inputs) {
      const milli = toMilli(input.value);
      if (milli > 0) {
        lines += 1;
        total += Math.floor((Number(input.dataset.price) * milli + 500) / 1000); // same rounding as the server
      }
    }
    formTotal.textContent = money(total);
    barTotal.textContent = money(total);
    barCount.textContent = lines + (lines === 1 ? ' item' : ' items');
    bar.hidden = lines === 0;
  };

  for (const input of inputs) {
    const wrap = input.parentElement;
    const step = Number(input.dataset.step) || 1000;
    const max = toMilli(input.max);
    const make = (label, text, delta) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = text;
      b.setAttribute('aria-label', label + ' ' + input.dataset.name);
      b.addEventListener('click', () => {
        const next = Math.min(max, Math.max(0, toMilli(input.value) + delta));
        input.value = next === 0 ? '' : fromMilli(next);
        update();
      });
      return b;
    };
    wrap.prepend(make('Less', '−', -step));
    wrap.append(make('More', '+', step));
    input.addEventListener('input', update);
  }

  const toggle = () => {
    const delivery = form.querySelector('input[name="fulfilment"]:checked');
    for (const section of form.querySelectorAll('[data-delivery-only]')) {
      section.hidden = delivery ? delivery.value !== 'delivery' : false;
    }
  };
  for (const radio of form.querySelectorAll('input[name="fulfilment"]')) radio.addEventListener('change', toggle);

  toggle();
  update();
})();
