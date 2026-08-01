/**
 * assets/js/admin.js
 * Клик по машино-месту в админке открывает карточку с деталями заявки
 * (ФИО, телефон, статус, ID платежа и т.д.), взятыми из data-атрибутов
 * кнопки .spot (сами атрибуты уже экранированы на сервере — см. admin/index.php).
 * Пустые места (без заявки) клика не обрабатывают.
 *
 * Особый случай — место с двойной оплатой (data-duplicate="1", ставится
 * сервером, когда по месту найдено 2+ заявки со статусом "оплачено" за
 * один и тот же месяц, см. resolveSpotDisplay() в includes/log-reader.php).
 * Для него карточка вместо одной заявки показывает предупреждение и
 * список ВСЕХ оплаченных заявок (парсится из data-payments), чтобы сразу
 * было видно оба платежа и можно было решить, за какой возвращать деньги.
 */
document.addEventListener('DOMContentLoaded', function () {
  var overlay = document.getElementById('detailOverlay');
  var body = document.getElementById('detailBody');
  var closeBtn = document.getElementById('detailClose');
  if (!overlay || !body || !closeBtn) return;

  function addRow(label, value) {
    var row = document.createElement('div');
    row.className = 'detail-row';

    var labelEl = document.createElement('span');
    labelEl.className = 'detail-label';
    labelEl.textContent = label;

    var valueEl = document.createElement('span');
    valueEl.className = 'detail-value';
    valueEl.textContent = value || '—';

    row.appendChild(labelEl);
    row.appendChild(valueEl);
    body.appendChild(row);
  }

  function openDuplicateDetail(btn) {
    var warn = document.createElement('div');
    warn.className = 'detail-warning';
    warn.textContent = 'Место оплачено несколько раз за этот месяц. Проверьте платежи ниже — вероятно, за один из них нужно оформить возврат.';
    body.appendChild(warn);

    addRow('Парковка', btn.dataset.parking);
    addRow('Место', '№' + btn.dataset.spot);
    addRow('Месяц', btn.dataset.month);

    var payments = [];
    try {
      payments = JSON.parse(btn.dataset.payments || '[]');
    } catch (e) {
      payments = [];
    }

    payments.forEach(function (p, i) {
      var heading = document.createElement('div');
      heading.className = 'detail-payment-heading';
      heading.textContent = 'Платёж ' + (i + 1) + ' из ' + payments.length;
      body.appendChild(heading);

      addRow('ФИО', p.fio);
      addRow('Телефон', p.phone);
      addRow('Дата заявки', p.date);
      addRow('Тариф', p.tariff);
      addRow('ID платежа', p.paymentId);
    });
  }

  function openDetail(btn) {
    body.innerHTML = '';

    if (btn.dataset.duplicate === '1') {
      openDuplicateDetail(btn);
      overlay.hidden = false;
      return;
    }

    addRow('Парковка', btn.dataset.parking);
    addRow('Место', '№' + btn.dataset.spot);
    addRow('Месяц', btn.dataset.month);
    addRow('Статус', btn.dataset.status);
    addRow('Тариф', btn.dataset.tariff);
    addRow('ФИО', btn.dataset.fio);
    addRow('Телефон', btn.dataset.phone);
    addRow('Дата заявки', btn.dataset.date);
    addRow('ID платежа', btn.dataset.paymentId);
    overlay.hidden = false;
  }

  function closeDetail() {
    overlay.hidden = true;
  }

  document.querySelectorAll('.spot').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!btn.dataset.status) return; // пустая ячейка — заявок за месяц нет
      openDetail(btn);
    });
  });

  closeBtn.addEventListener('click', closeDetail);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeDetail();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeDetail();
  });
});
