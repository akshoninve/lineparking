/**
 * assets/js/main.js
 * Фронтенд-скрипт главной страницы (index.php):
 *   1) кнопки «Копировать» в блоке реквизитов;
 *   2) автоматический пересчёт суммы к оплате в форме заявки;
 *   3) автозаглавные буквы в ФИО;
 *   4) автоформат телефона (+7 ...).
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

  // ---------- Автозаглавные буквы в ФИО ----------
  // Заглавная буква после начала строки, пробела или дефиса
  // (учитывает и «Иванов Иван Иванович», и «Петров-Водкин»).
  // Курсор не прыгает, т.к. uppercase не меняет длину кириллической строки.
  var fioEl = document.getElementById('fio');
  if (fioEl) {
    fioEl.addEventListener('input', function () {
      var pos = fioEl.selectionStart;
      var before = fioEl.value;
      var after = before.replace(/(^|[\s-])([а-яёa-z])/gi, function (m, sep, ch) {
        return sep + ch.toUpperCase();
      });
      if (after !== before) {
        fioEl.value = after;
        fioEl.setSelectionRange(pos, pos);
      }
    });
  }

  // ---------- Автоформат телефона (+7) ----------
  // При фокусе на пустом поле сразу подставляет "+7 ". При вводе
  // "8" в начале автоматически заменяется на "+7", формат — как
  // в placeholder: +7 900 000-00-00.
  var phoneEl = document.getElementById('phone');
  if (phoneEl) {
    phoneEl.addEventListener('focus', function () {
      if (!phoneEl.value) {
        phoneEl.value = '+7 ';
        phoneEl.setSelectionRange(3, 3);
      }
    });
    phoneEl.addEventListener('input', function () {
      var digits = phoneEl.value.replace(/\D/g, '');
      if (digits.charAt(0) === '8') digits = '7' + digits.slice(1);
      if (digits && digits.charAt(0) !== '7') digits = '7' + digits;
      digits = digits.slice(0, 11);

      var formatted = '';
      if (digits.length > 0) {
        formatted = '+7';
        if (digits.length > 1) formatted += ' ' + digits.slice(1, 4);
        if (digits.length > 4) formatted += ' ' + digits.slice(4, 7);
        if (digits.length > 7) formatted += '-' + digits.slice(7, 9);
        if (digits.length > 9) formatted += '-' + digits.slice(9, 11);
      }
      phoneEl.value = formatted;
    });
  }

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

  // Переключатель "Полный месяц" / "Свои даты" — обычные <button>
  // (не <input type="radio">: нативный вид радиокнопки браузер может
  // отрисовать поверх кастомных стилей, кнопки этой проблемы лишены).
  // Выбор хранится в скрытом поле periodMode и уходит на сервер вместе
  // с формой.
  var modeToggle = document.getElementById('periodModeToggle');
  var modeInput = document.getElementById('periodMode');
  var monthField = document.getElementById('periodMonthField');
  var daysField = document.getElementById('periodDaysField');
  var dateFromEl = document.getElementById('date_from');
  var dateToEl = document.getElementById('date_to');

  if (!parkingEl || !spotEl || !monthEl || !totalAmount) return;

  function currentMode() {
    return modeInput ? modeInput.value : 'month';
  }

  function isPremium(spotNum) {
    return premiumRanges.some(function (r) { return spotNum >= r[0] && spotNum <= r[1]; });
  }
  function formatRub(n) {
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
  }

  // ---------- Переключение режима "Полный месяц" / "Свои даты" ----------
  function setMode(mode) {
    if (modeInput) modeInput.value = mode;
    if (modeToggle) {
      Array.prototype.forEach.call(modeToggle.querySelectorAll('.mode-card'), function (btn) {
        btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
      });
    }
    if (monthField) monthField.hidden = mode === 'days';
    if (daysField) daysField.hidden = mode !== 'days';
    recalc();
  }
  if (modeToggle) {
    Array.prototype.forEach.call(modeToggle.querySelectorAll('.mode-card'), function (btn) {
      btn.addEventListener('click', function () { setMode(btn.getAttribute('data-mode')); });
    });
  }

  // Число дней в конкретном месяце — тот же принцип, что и в
  // includes/functions.php::calculatePartialPeriodPrice() на сервере
  // (day 0 следующего месяца = последний день текущего).
  function daysInMonth(year, monthIndex0) {
    return new Date(year, monthIndex0 + 1, 0).getDate();
  }

  // Человекочитаемый текст периода — та же логика, что и
  // includes/functions.php::periodDatesToText() на сервере, чтобы
  // текст в "Итого" совпадал с тем, что уйдёт в чек/лог/письмо:
  //   один день:  "04.06.26"
  //   период:     "с 04.06.26 по 06.09.26"
  function pad2(n) { return n < 10 ? '0' + n : '' + n; }
  function fmtDMY(d) {
    return pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) + '.' + ('' + d.getFullYear()).slice(-2);
  }
  function periodDatesToText(fromStr, toStr) {
    var from = new Date(fromStr + 'T00:00:00');
    var to = new Date(toStr + 'T00:00:00');
    if (fromStr === toStr) {
      return fmtDMY(from);
    }
    return 'с ' + fmtDMY(from) + ' по ' + fmtDMY(to);
  }

  // Считает { days, total } за период дат — период МОЖЕТ пересекать
  // границу одного или нескольких календарных месяцев (и год), это
  // один платёж. Делим период на отрезки по месяцам и в каждом
  // отрезке применяем свою дневную ставку (тариф/дней-в-этом-месяце) —
  // ровно та же логика, что и на сервере
  // (includes/functions.php::calculatePartialPeriodPrice()), чтобы
  // показанная клиенту сумма всегда совпадала с той, что реально
  // спишется после отправки формы. Округление — один раз, по итоговой
  // сумме всех отрезков, вверх до рубля.
  function calcDaysPeriod(fromStr, toStr, monthlyPrice) {
    if (!fromStr || !toStr) return null;
    var from = new Date(fromStr + 'T00:00:00');
    var to = new Date(toStr + 'T00:00:00');
    if (isNaN(from) || isNaN(to) || to < from) return null;

    var msPerDay = 24 * 60 * 60 * 1000;
    var totalDays = Math.round((to - from) / msPerDay) + 1;
    var totalAmount = 0;
    var cursor = new Date(from.getTime());

    while (cursor <= to) {
      var dim = daysInMonth(cursor.getFullYear(), cursor.getMonth());
      var monthEnd = new Date(cursor.getFullYear(), cursor.getMonth(), dim);
      var segmentEnd = monthEnd < to ? monthEnd : to;
      var segmentDays = Math.round((segmentEnd - cursor) / msPerDay) + 1;
      var perDay = monthlyPrice / dim;
      totalAmount += perDay * segmentDays;
      cursor = new Date(segmentEnd.getTime() + msPerDay);
    }

    return { days: totalDays, total: Math.ceil(totalAmount) };
  }

  function recalc() {
    var parking = parkingEl.value;
    var spotNum = parseInt(spotEl.value, 10);
    var mode = currentMode();
    var monthlyPrice = (parking === 'levitan' && isPremium(spotNum)) ? premiumPrice : basePrice;
    var parkingLabel = parkingNames[parking] || '';

    if (!parking || !spotNum) {
      totalAmount.textContent = '— выберите парковку и место —';
      totalBox.classList.remove('ot-ready');
      if (payButton) payButton.textContent = 'Перейти к оплате';
      return;
    }

    if (mode === 'days') {
      var period = calcDaysPeriod(dateFromEl.value, dateToEl.value, monthlyPrice);
      if (!period) {
        totalAmount.textContent = '— укажите даты периода —';
        totalBox.classList.remove('ot-ready');
        if (payButton) payButton.textContent = 'Перейти к оплате';
        return;
      }
      var periodLabel = periodDatesToText(dateFromEl.value, dateToEl.value) + ' (' + period.days + ' дн.)';
      var detailDays = (parkingLabel ? parkingLabel + ' · ' : '') + '№' + spotNum + ' · ' + periodLabel;
      totalAmount.textContent = formatRub(period.total) + ' — ' + detailDays;
      totalBox.classList.add('ot-ready');
      if (payButton) payButton.textContent = 'Оплатить ' + formatRub(period.total);
      return;
    }

    var month = monthEl.value;
    var detail = (parkingLabel ? parkingLabel + ' · ' : '') + '№' + spotNum + (month ? ' · ' + month : '');
    totalAmount.textContent = formatRub(monthlyPrice) + ' — ' + detail;
    totalBox.classList.add('ot-ready');
    if (payButton) payButton.textContent = 'Оплатить ' + formatRub(monthlyPrice);
  }

  [parkingEl, spotEl, monthEl].forEach(function (el) {
    el.addEventListener('input', recalc);
    el.addEventListener('change', recalc);
  });
  if (dateFromEl) {
    dateFromEl.addEventListener('change', function () {
      // Не даём выбрать "по" раньше "с" — подтягиваем min динамически.
      if (dateToEl) dateToEl.min = dateFromEl.value || dateToEl.min;
      recalc();
    });
  }
  if (dateToEl) dateToEl.addEventListener('change', recalc);

  setMode(currentMode());
  recalc();
});
