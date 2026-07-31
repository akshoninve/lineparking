/**
 * assets/js/main.js
 * Фронтенд-скрипт главной страницы (index.php):
 *   1) кнопки «Копировать» в блоке реквизитов;
 *   2) автоматический пересчёт суммы к оплате в форме заявки.
 *
 * Ожидает глобальный объект window.LP_CONFIG, который index.php
 * формирует маленьким инлайн-скриптом непосредственно перед
 * подключением этого файла:
 *   window.LP_CONFIG = { basePrice, premiumPrice, premiumRanges };
 * Так PHP-данные (цены, диапазоны мест) остаются в PHP-шаблоне,
 * а сама логика — в статическом, кэшируемом JS-файле.
 */
document.addEventListener('DOMContentLoaded', function () {

  // ---------- Показать/скрыть блок реквизитов ----------
  // По умолчанию блок скрыт атрибутом [hidden] в разметке (index.php),
  // так на мобильных не приходится скроллить мимо широкой таблицы,
  // чтобы попасть к остальному контенту. Кнопка работает одинаково
  // и на мобильной, и на десктопной версии.
  var reqToggle = document.getElementById('reqToggle');
  var reqCard = document.getElementById('reqCard');
  if (reqToggle && reqCard) {
    var reqToggleText = reqToggle.querySelector('.req-toggle-text');
    reqToggle.addEventListener('click', function () {
      var isHidden = reqCard.hasAttribute('hidden');
      if (isHidden) {
        reqCard.removeAttribute('hidden');
        reqToggle.setAttribute('aria-expanded', 'true');
        if (reqToggleText) reqToggleText.textContent = 'Скрыть реквизиты';
      } else {
        reqCard.setAttribute('hidden', '');
        reqToggle.setAttribute('aria-expanded', 'false');
        if (reqToggleText) reqToggleText.textContent = 'Показать реквизиты';
      }
    });
  }

  // ---------- Копирование реквизитов по клику ----------
  document.querySelectorAll('.copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      navigator.clipboard.writeText(text).then(function () {
        var old = btn.textContent;
        btn.textContent = 'Скопировано';
        setTimeout(function () { btn.textContent = old; }, 1500);
      });
    });
  });

  // ---------- Пересчёт суммы в форме оплаты ----------
  var cfg = window.LP_CONFIG || {};
  var basePrice = cfg.basePrice || 0;
  var premiumPrice = cfg.premiumPrice || 0;
  var premiumRanges = cfg.premiumRanges || [];
  var parkingNames = cfg.parkingNames || {};

  var parkingEl = document.getElementById('parking');
  var spotEl = document.getElementById('spot');
  var monthEl = document.getElementById('month');
  var totalBox = document.getElementById('orderTotal');
  var totalAmount = document.getElementById('orderTotalAmount');
  var payButton = document.getElementById('payButton');
  if (!parkingEl || !spotEl || !monthEl || !totalAmount) return;

  function isPremium(spotNum) {
    return premiumRanges.some(function (r) { return spotNum >= r[0] && spotNum <= r[1]; });
  }
  function formatRub(n) {
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
  }
  function recalc() {
    var parking = parkingEl.value;
    var spotNum = parseInt(spotEl.value, 10);
    var month = monthEl.value;

    if (!parking || !spotNum) {
      totalAmount.textContent = '— выберите парковку и место —';
      totalBox.classList.remove('ot-ready');
      if (payButton) payButton.textContent = 'Перейти к оплате';
      return;
    }
    var price = (parking === 'levitan' && isPremium(spotNum)) ? premiumPrice : basePrice;
    var parkingLabel = parkingNames[parking] || '';
    var detail = (parkingLabel ? parkingLabel + ' · ' : '') + '№' + spotNum + (month ? ' · ' + month : '');
    totalAmount.textContent = formatRub(price) + ' — ' + detail;
    totalBox.classList.add('ot-ready');
    if (payButton) payButton.textContent = 'Оплатить ' + formatRub(price);
  }

  [parkingEl, spotEl, monthEl].forEach(function (el) {
    el.addEventListener('input', recalc);
    el.addEventListener('change', recalc);
  });
  recalc();
});