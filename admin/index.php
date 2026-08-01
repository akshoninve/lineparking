<?php
/**
 * admin/index.php
 * Админка: статус оплаты машино-мест по каждой парковке за выбранный
 * месяц (с годом, например "Июль 2026"). Источник данных — лог заявок
 * (private/logs/zayavki-<год>.log), см. includes/log-reader.php.
 *
 * Статус места считается через resolveSpotDisplay() (log-reader.php) по
 * ВСЕМ заявкам этого места за месяц, а не только по последней — это
 * нужно, чтобы отдельно показать место, оплаченное два раза и более
 * ("дубль оплаты", отдельный цвет в сетке), и увидеть оба платежа сразу.
 *
 * Ниже сетки — «Журнал событий»: хронологический список ВСЕХ заявок
 * за тот же выбранный месяц (getEntriesForMonth() в log-reader.php),
 * скрыт под кнопкой по умолчанию (как блок «Реквизиты» на index.php).
 * Отдельного логина для него не нужно — вся страница уже защищена
 * requireAdminLogin() выше.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/log-reader.php';

requireAdminLogin();

$entries = loadZayavkiLog();

$selectedMonth = $_GET['month'] ?? '';
if (!in_array($selectedMonth, $months, true)) {
    // По умолчанию — текущий календарный месяц (с годом).
    $selectedMonth = $currentMonthYear;
}

$statusesByParking = getSpotStatusesForMonth($entries, $parkings, $selectedMonth);
$monthEntries      = getEntriesForMonth($entries, $selectedMonth);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Панель администратора — ЛайнПаркинг</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<header class="admin-header">
  <div class="wrap admin-header-row">
    <div class="brand"><span class="p-sign">P</span>ЛайнПаркинг · Панель администратора</div>
    <a class="logout-link" href="logout.php">Выйти</a>
  </div>
</header>
<div class="stripes"></div>

<main class="wrap admin-main">

  <form method="get" class="month-form">
    <label for="month">Месяц</label>
    <select id="month" name="month" onchange="this.form.submit()">
      <?php foreach ($months as $m): ?>
      <option value="<?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php foreach ($parkings as $key => [$name, $capacity]):
      $spotStatuses = $statusesByParking[$key];
      $summary = summarizeParkingStatuses($spotStatuses);
  ?>
  <section class="parking-block">
    <div class="parking-head">
      <h2><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></h2>
      <div class="summary mono">
        <span class="s-paid"><?= (int)$summary['paid'] ?> оплачено</span>
        <span class="s-pending"><?= (int)$summary['pending'] ?> ожидает</span>
        <?php if ($summary['duplicate'] > 0): ?>
        <span class="s-duplicate">⚠ <?= (int)$summary['duplicate'] ?> дубль оплаты</span>
        <?php endif; ?>
        <span class="s-empty"><?= (int)$summary['empty'] ?> без заявки</span>
        <span class="s-total">из <?= (int)$summary['total'] ?></span>
      </div>
    </div>
    <div class="spot-grid">
      <?php foreach ($spotStatuses as $spotNum => $spotEntries):
          $resolved = resolveSpotDisplay($spotEntries);
          $entry    = $resolved['entry'];

          switch ($resolved['state']) {
              case 'empty':
                  $cls = 'spot-empty';
                  $titleAttr = 'Заявок нет';
                  break;
              case 'duplicate':
                  $cls = 'spot-duplicate';
                  $titleAttr = 'Оплачено ' . count($resolved['paidEntries']) . ' раза — требует проверки';
                  break;
              case 'paid':
                  $cls = 'spot-paid';
                  $titleAttr = 'Оплачено';
                  break;
              default:
                  $cls = 'spot-pending';
                  $titleAttr = $entry['status'] ?? 'Статус неизвестен';
          }
      ?>
      <button
        type="button"
        class="spot <?= $cls ?>"
        title="<?= htmlspecialchars($titleAttr, ENT_QUOTES, 'UTF-8') ?>"
        <?php if ($entry !== null): ?>
        data-fio="<?= htmlspecialchars($entry['fio'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-phone="<?= htmlspecialchars($entry['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-date="<?= htmlspecialchars($entry['date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-tariff="<?= htmlspecialchars($entry['tariff'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-status="<?= htmlspecialchars($entry['status'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-payment-id="<?= htmlspecialchars($entry['payment_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-parking="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
        data-spot="<?= (int)$spotNum ?>"
        data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') ?>"
        <?php if ($resolved['state'] === 'duplicate'):
            // Список ВСЕХ оплаченных заявок по этому месту — кладём в один
            // JSON-атрибут, а не по одному data-* на платёж, т.к. их
            // количество заранее не известно (может быть и 3, и 4 оплаты).
            // admin.js разбирает этот JSON и рисует карточку на каждый платёж.
            $paymentsForJs = array_map(function ($e) {
                return [
                    'fio'       => $e['fio'] ?? '',
                    'phone'     => $e['phone'] ?? '',
                    'date'      => $e['date'] ?? '',
                    'tariff'    => $e['tariff'] ?? '',
                    'paymentId' => $e['payment_id'] ?? '',
                ];
            }, $resolved['paidEntries']);
        ?>
        data-duplicate="1"
        data-payments="<?= htmlspecialchars(json_encode($paymentsForJs, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
        <?php endif; ?>
        <?php endif; ?>
      ><?= (int)$spotNum ?></button>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <section class="log-section">
    <button type="button" class="log-toggle" id="logToggle" aria-expanded="false" aria-controls="logCard">
      <span class="log-toggle-text">Показать журнал событий</span>
      <span class="chevron" aria-hidden="true">&#9662;</span>
    </button>
    <noscript><style>#logCard{display:block!important}#logToggle{display:none}</style></noscript>

    <div class="log-card" id="logCard" hidden>
      <div class="log-head">
        <div class="log-title">Журнал заявок · <?= htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="log-sub mono">Хронологический порядок — как в логе, все парковки вместе</div>
      </div>
      <?php if (empty($monthEntries)): ?>
      <div class="log-empty">За «<?= htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') ?>» заявок нет.</div>
      <?php else: ?>
      <div class="log-table-wrap">
        <table class="log-table">
          <thead>
            <tr>
              <th>Дата заявки</th>
              <th>ФИО</th>
              <th>Телефон</th>
              <th>Парковка</th>
              <th>Место</th>
              <th>Тариф</th>
              <th>Статус</th>
              <th>ID платежа</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($monthEntries as $e): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($e['date'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($e['fio'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              <td class="mono"><?= htmlspecialchars($e['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($e['parking'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              <td>№<?= htmlspecialchars((string)($e['spot'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($e['tariff'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <?php $st = $e['status'] ?? ''; ?>
                <span class="log-status <?= $st === 'оплачено' ? 's-paid' : 's-pending' ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span>
              </td>
              <td class="mono"><?= htmlspecialchars($e['payment_id'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="admin-footer-info">
    <div class="info-block legend-block">
      <h3>Обозначения цветов</h3>
      <div class="legend-items">
        <div class="legend-item"><span class="legend-swatch spot-paid"></span>Оплачено</div>
        <div class="legend-item"><span class="legend-swatch spot-pending"></span>Ожидает оплаты / другой незавершённый статус</div>
        <div class="legend-item"><span class="legend-swatch spot-empty"></span>Заявок нет</div>
        <div class="legend-item"><span class="legend-swatch spot-duplicate"></span>Оплачено дважды и более — требует проверки</div>
      </div>
    </div>

    <?php
    // Тарифы берём из тех же переменных, что и главная страница сайта
    // ($pricePerMonth, $levitanPremiumPrice, $levitanPremiumRanges,
    // $levitanPremiumCount) — они заданы константами в private/config.php
    // (раздел "ТАРИФЫ") и приходят сюда через includes/parkings-data.php,
    // который подключает bootstrap.php. Значит если поменять цены в
    // config.php на сервере, этот блок обновится сам, без правок кода.
    ?>
    <div class="info-block tariffs-block">
      <h3>Текущие тарифы</h3>
      <table class="tariffs-table mono">
        <tr>
          <td class="t-label">Базовый тариф (все парковки)</td>
          <td class="t-value"><?= number_format($pricePerMonth, 0, ',', ' ') ?> ₽/мес</td>
        </tr>
        <tr>
          <td class="t-label">«Левитан», повышенная категория<br><span class="t-sub">места <?= levitanPremiumRangesText($levitanPremiumRanges) ?> (<?= (int)$levitanPremiumCount ?> мест)</span></td>
          <td class="t-value"><?= number_format($levitanPremiumPrice, 0, ',', ' ') ?> ₽/мес</td>
        </tr>
      </table>
    </div>
  </section>

</main>

<div class="detail-overlay" id="detailOverlay" hidden>
  <div class="detail-panel" id="detailPanel">
    <button type="button" class="detail-close" id="detailClose" aria-label="Закрыть">&times;</button>
    <div class="detail-body" id="detailBody"></div>
  </div>
</div>

<script src="../assets/js/admin.js" defer></script>
</body>
</html>
