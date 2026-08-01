<?php
/**
 * index.php
 * ЛАЙНПАРКИНГ — форма оплаты машино-места.
 *
 * Файл теперь отвечает только за разметку (HTML) и подстановку
 * переменных. Вся логика вынесена:
 *   - bootstrap.php               — подключает config.php (выше public_html)
 *                                    и общие include-файлы;
 *   - includes/form-handler.php   — обработка POST-запроса формы;
 *   - includes/robokassa.php      — интеграция с Robokassa;
 *   - includes/parkings-data.php  — тарифы и список парковок;
 *   - assets/css/style.css        — стили;
 *   - assets/js/main.js           — интерактив (пересчёт суммы, копирование).
 *
 * ПОДКЛЮЧЕНИЕ ОПЛАТЫ ROBOKASSA: впишите ключи в /home/srv250266/config.php
 * (см. подробную инструкцию в комментарии там же и в includes/robokassa.php).
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/form-handler.php';

// Значение месяца, которое должно быть выбрано в селекторе:
// то, что уже ввёл клиент (если форма отправлялась и есть ошибки),
// иначе — "текущий месяц + 1" по умолчанию (см. parkings-data.php).
$selectedMonthValue = $submitted['month'] !== '' ? $submitted['month'] : $defaultPaymentMonth;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ЛАЙНПАРКИНГ — паркинги в Видном</title>
<meta name="description" content="Парковки «Левитан», «Купелинка» в г. Видное. Абонемент от 10 000 ₽/месяц. Оплата парковочного места онлайн."><!-- «Нестеров» временно скрыта, см. includes/parkings-data.php — вернуть упоминание сюда вместе с ней -->
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="hero">
  <nav class="topbar">
    <a class="brand" href="index.php"><span class="p-sign">P</span>ЛайнПаркинг</a>
    <a class="cta-link" href="#form">Оплатить место</a>
  </nav>
  <div class="wrap sub-links"><a href="tel:<?= COMPANY_PHONE_HREF ?>"><?= COMPANY_PHONE_DISPLAY ?></a><span>·</span><a href="oferta.php" target="_blank" rel="noopener">Публичная оферта</a></div>
  <div class="hero-body">
    <div>
      <h1>ЛайнПаркинг<br><span>Видное</span></h1>
      <p class="lead">Паркинги в г. Видное — «Левитан» и «Купелинка». Оплатите парковку онлайн, за пару минут: заполните заявку, совершите оплату, мы пришлём подтверждение.<!-- «Нестеров» временно скрыта, см. includes/parkings-data.php — вернуть в текст вместе с ней --></p>
      <div class="hero-actions">
        <a href="#form" class="btn btn-primary">Оплатить машино-место</a>
        <a href="#requisites" class="btn btn-outline">Реквизиты для оплаты</a>
      </div>
    </div>
    <div class="barrier" aria-hidden="true">
      <div class="post"></div>
      <div class="arm"></div>
      <div class="base"></div>
    </div>
  </div>
</header>
<div class="stripes"></div>

<section class="lots-section">
  <div class="wrap">
    <div class="eyebrow">Наши объекты · г. Видное</div>
    <h2 class="section-title">Паркинги ЛайнПаркинг</h2>
    <p class="section-sub">Каждая парковка закреплена за отдельным домом в г. Видное. Уточните номер своего машино-места (он указан на самом месте или у администратора парковки) и впишите его в заявку ниже.</p>
    <div class="lots">
      <?php foreach ($parkings as $key => [$name, $capacity]): ?>
      <div class="lot-row">
        <div class="lot-main">
          <div class="lot-name"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></div>
          <?php if ($key === 'levitan'): ?>
          <div class="lot-price">10 000 – 12 000 ₽/мес</div>
          <div class="lot-foot">Оплата — ежемесячно, до 1-го числа. М/м <?= levitanPremiumRangesText($levitanPremiumRanges) ?> — <?= number_format($levitanPremiumPrice, 0, ',', ' ') ?> ₽/мес, остальные места — <?= number_format($pricePerMonth, 0, ',', ' ') ?> ₽/мес.</div>
          <?php else: ?>
          <div class="lot-price"><?= number_format($pricePerMonth, 0, ',', ' ') ?> ₽/мес</div>
          <div class="lot-foot">Оплата — ежемесячно, до 1-го числа</div>
          <?php endif; ?>
        </div>
        <div class="lot-board">
          <span class="num"><?= (int)$capacity ?></span>
          <span class="unit">машино-мест</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="price-section">
  <div class="wrap">
    <div class="price-card">
      <div class="price-body">
        <h3>Стоимость и как всё устроено</h3>
        <ul>
          <li><strong>Что входит:</strong> закреплённое машино-место на охраняемой территории, круглосуточный доступ.</li>
          <li><strong>Оплата:</strong> фиксированная — <?= number_format($pricePerMonth, 0, ',', ' ') ?> ₽ в месяц, без скрытых платежей. Исключение — парковка «Левитан»: машино-места <?= levitanPremiumRangesText($levitanPremiumRanges) ?> (<?= $levitanPremiumCount ?> мест повышенной категории) стоят <?= number_format($levitanPremiumPrice, 0, ',', ' ') ?> ₽ в месяц, остальные места на этой парковке — по базовой цене <?= number_format($pricePerMonth, 0, ',', ' ') ?> ₽. Тариф определяется автоматически по номеру места. Оплата производится авансом за предстоящий месяц.</li>
          <li><strong>Как получить доступ:</strong> после поступления оплаты место активируется в системе, и в течение 1 рабочего дня открывается доступ по телефону: звонок на шлагбаум открывает его.</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section class="ticket-section" id="form">
  <div class="wrap">
    <div class="ticket-grid">
      <div>
        <div class="eyebrow">Оплата</div>
        <h2 class="section-title">Оплата парковочного места</h2>
        <p class="section-sub">Заполните форму — мы автоматически посчитаем сумму по номеру вашего машино-места и переведём вас на защищённую страницу оплаты.</p>

        <?php if ($returnedFromPayment): ?>
        <div class="success-box">
          <strong>Вы вернулись со страницы оплаты</strong>
          Если оплата прошла успешно, вы получите подтверждение от платёжного оператора. Если платёж не завершился — просто заполните форму ещё раз.
        </div>
        <?php elseif ($success && $paymentUnavailable): ?>
        <div class="success-box">
          <strong>Заявка принята</strong>
          Онлайн-оплата картой сейчас подключается (сервис проходит модерацию), поэтому заявка сохранена<?= $lastTariff ? " на сумму {$lastTariff}" : '' ?>. Переведите оплату по реквизитам справа — раздел «Реквизиты», указав в назначении платежа парковку, номер места и месяц. Как только онлайн-оплата заработает, кнопка ниже будет сразу переводить на оплату картой.
        </div>
        <?php endif; ?>
      </div>

      <div class="ticket">
        <div class="ticket-title">Оплата места · ЛайнПаркинг</div>
        <form method="post" action="#form" novalidate>
          <div class="field <?= isset($errors['fio']) ? 'has-err' : '' ?>">
            <label for="fio">ФИО</label>
            <input type="text" id="fio" name="fio" placeholder="Иванов Иван Иванович" value="<?= htmlspecialchars($submitted['fio'], ENT_QUOTES, 'UTF-8') ?>">
            <?php if (isset($errors['fio'])): ?><div class="err"><?= $errors['fio'] ?></div><?php endif; ?>
          </div>

          <div class="field <?= isset($errors['phone']) ? 'has-err' : '' ?>">
            <label for="phone">Номер телефона</label>
            <input type="tel" id="phone" name="phone" placeholder="+7 900 000-00-00" value="<?= htmlspecialchars($submitted['phone'], ENT_QUOTES, 'UTF-8') ?>">
            <?php if (isset($errors['phone'])): ?><div class="err"><?= $errors['phone'] ?></div><?php endif; ?>
          </div>

          <div class="row2">
            <div class="field <?= isset($errors['parking']) ? 'has-err' : '' ?>">
              <label for="parking">Парковка</label>
              <select id="parking" name="parking">
                <option value="">Выберите</option>
                <?php foreach ($parkings as $key => [$name, $capacity]): ?>
                <option value="<?= $key ?>" <?= $submitted['parking'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['parking'])): ?><div class="err"><?= $errors['parking'] ?></div><?php endif; ?>
            </div>

            <div class="field <?= isset($errors['spot']) ? 'has-err' : '' ?>">
              <label for="spot">№ машино-места</label>
              <input type="text" inputmode="numeric" id="spot" name="spot" placeholder="42" value="<?= htmlspecialchars($submitted['spot'], ENT_QUOTES, 'UTF-8') ?>">
              <?php if (isset($errors['spot'])): ?><div class="err"><?= $errors['spot'] ?></div><?php endif; ?>
            </div>
          </div>

          <div class="field <?= isset($errors['month']) ? 'has-err' : '' ?>">
            <label for="month">За какой месяц оплата</label>
            <select id="month" name="month">
              <?php foreach ($months as $m): ?>
              <option value="<?= $m ?>" <?= $selectedMonthValue === $m ? 'selected' : '' ?>><?= $m ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['month'])): ?><div class="err"><?= $errors['month'] ?></div><?php endif; ?>
          </div>

          <div class="order-total" id="orderTotal">
            <span class="ot-label">Итого к оплате</span>
            <span class="ot-amount" id="orderTotalAmount">— выберите парковку и место —</span>
          </div>

          <div class="field agree-field <?= isset($errors['agree']) ? 'has-err' : '' ?>">
            <label class="agree-label">
              <input type="checkbox" name="agree" value="1" <?= $submitted['agree'] === '1' ? 'checked' : '' ?>>
              <span>Я принимаю условия <a href="oferta.php" target="_blank" rel="noopener">публичной оферты</a> и согласен(-на) на обработку персональных данных</span>
            </label>
            <?php if (isset($errors['agree'])): ?><div class="err"><?= $errors['agree'] ?></div><?php endif; ?>
          </div>

          <div class="submit-row">
            <button type="submit" name="send_request" value="1" class="btn btn-primary" id="payButton">Перейти к оплате</button>
          </div>
          <div class="fine-print">Услуга — предоставление машино-места в аренду на 1 календарный месяц. Стоимость — от 10 000 ₽ (для мест <?= levitanPremiumRangesText($levitanPremiumRanges) ?> на парковке «Левитан» — 12 000 ₽/мес), сумма рассчитывается автоматически по номеру места. После нажатия кнопки вы попадёте на защищённую страницу оплаты картой.</div>
        </form>
      </div>
    </div>
  </div>
</section>

<section class="req-section" id="requisites">
  <div class="wrap">
    <div class="eyebrow">Реквизиты</div>
    <h2 class="section-title">Платёжные реквизиты</h2>
    <p class="section-sub">Основной способ оплаты — банковской картой через форму выше. Эти реквизиты нужны для случаев ручного перевода (например, пока подключается онлайн-оплата) и для юридических лиц. В назначении платежа укажите: парковку, номер машино-места и месяц.</p>

    <button type="button" class="req-toggle" id="reqToggle" aria-expanded="false" aria-controls="reqCard">
      <span class="req-toggle-text">Показать реквизиты</span>
      <span class="chevron" aria-hidden="true">&#9662;</span>
    </button>
    <noscript><style>#reqCard{display:block!important}#reqToggle{display:none}</style></noscript>

    <div class="req-card" id="reqCard" hidden>
      <div class="req-head">
        <div class="co"><?= COMPANY_NAME_SHORT ?></div>
        <div class="co-short mono">ИНН <?= COMPANY_INN ?> · КПП <?= COMPANY_KPP ?></div>
      </div>
      <table class="req-table">
        <tr>
          <td class="label">Полное наименование</td>
          <td class="value"><?= COMPANY_NAME_FULL ?><button class="copy-btn" data-copy="<?= htmlspecialchars(COMPANY_NAME_FULL, ENT_QUOTES, 'UTF-8') ?>">Копировать</button></td>
        </tr>
        <tr>
          <td class="label">Юридический адрес</td>
          <td class="value"><?= COMPANY_ADDRESS ?></td>
        </tr>
        <tr>
          <td class="label">Фактический адрес</td>
          <td class="value"><?= COMPANY_ADDRESS ?></td>
        </tr>
        <tr>
          <td class="label">ОГРН</td>
          <td class="value"><?= COMPANY_OGRN ?></td>
        </tr>
        <tr>
          <td class="label">ИНН</td>
          <td class="value"><?= COMPANY_INN ?><button class="copy-btn" data-copy="<?= COMPANY_INN ?>">Копировать</button></td>
        </tr>
        <tr>
          <td class="label">КПП</td>
          <td class="value"><?= COMPANY_KPP ?></td>
        </tr>
        <tr>
          <td class="label">Расчётный счёт</td>
          <td class="value"><?= COMPANY_ACCOUNT ?><button class="copy-btn" data-copy="<?= COMPANY_ACCOUNT ?>">Копировать</button></td>
        </tr>
        <tr>
          <td class="label">Корр. счёт</td>
          <td class="value"><?= COMPANY_CORR_ACCOUNT ?></td>
        </tr>
        <tr>
          <td class="label">БИК</td>
          <td class="value"><?= COMPANY_BIK ?></td>
        </tr>
        <tr>
          <td class="label">Банк</td>
          <td class="value"><?= COMPANY_BANK ?></td>
        </tr>
        <tr>
          <td class="label">Генеральный директор</td>
          <td class="value"><?= COMPANY_DIRECTOR ?></td>
        </tr>
        <tr>
          <td class="label">Электронная почта</td>
          <td class="value"><a href="mailto:<?= COMPANY_EMAIL ?>"><?= COMPANY_EMAIL ?></a></td>
        </tr>
        <tr>
          <td class="label">Контактный телефон</td>
          <td class="value"><a href="tel:<?= COMPANY_PHONE_HREF ?>"><?= COMPANY_PHONE_DISPLAY ?></a></td>
        </tr>
      </table>
    </div>
  </div>
</section>

<footer>
  <div class="wrap foot-grid">
    <div class="brand">ЛайнПаркинг · г. Видное</div>
    <div><a href="tel:<?= COMPANY_PHONE_HREF ?>"><?= COMPANY_PHONE_DISPLAY ?></a> &nbsp;·&nbsp; <a href="mailto:<?= COMPANY_EMAIL ?>"><?= COMPANY_EMAIL ?></a> &nbsp;·&nbsp; <a href="oferta.php" target="_blank" rel="noopener">Публичная оферта</a></div>
    <div>&copy; <?= date('Y') ?> <?= COMPANY_NAME_SHORT ?></div>
  </div>
</footer>

<script>
  // Небольшой инлайн-блок передаёт PHP-данные (цены, диапазоны мест)
  // в статический assets/js/main.js — сам скрипт логики в PHP не зависит.
  window.LP_CONFIG = {
    basePrice: <?= (int)$pricePerMonth ?>,
    premiumPrice: <?= (int)$levitanPremiumPrice ?>,
    premiumRanges: <?= json_encode($levitanPremiumRanges) ?>,
    parkingNames: <?= json_encode(array_map(
        fn($p) => preg_replace('/^Парковка\s*/u', '', $p[0]),
        $parkings
    ), JSON_UNESCAPED_UNICODE) ?>
  };
</script>
<script src="assets/js/main.js" defer></script>

</body>
</html>
