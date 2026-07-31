/**
 * assets/js/admin.js
 * Клик по машино-месту в админке открывает карточку с деталями заявки
 * (ФИО, телефон, статус, ID платежа и т.д.), взятыми из data-атрибутов
 * кнопки .spot (сами атрибуты уже экранированы на сервере — см. admin/index.php).
 * Пустые места (без заявки) клика не обрабатывают.
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

  function openDetail(btn) {
    body.innerHTML = '';
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
