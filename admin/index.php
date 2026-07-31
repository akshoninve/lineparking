<?php
/**
 * admin/index.php
 * Админка: статус оплаты машино-мест по каждой парковке за выбранный
 * месяц (с годом, например "Июль 2026"). Источник данных — лог заявок
 * (private/logs/zayavki-<год>.log), см. includes/log-reader.php.
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Оплаты мест — Админка ЛайнПаркинг</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<header class="admin-header">
  <div class="wrap admin-header-row">
    <div class="brand"><span class="p-sign">P</span>ЛайнПаркинг · Админка</div>
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
        <span class="s-empty"><?= (int)$summary['empty'] ?> без заявки</span>
        <span class="s-total">из <?= (int)$summary['total'] ?></span>
      </div>
    </div>
    <div class="spot-grid">
      <?php foreach ($spotStatuses as $spotNum => $entry):
          if ($entry === null) {
              $cls = 'spot-empty';
              $titleAttr = 'Заявок нет';
          } elseif (($entry['status'] ?? '') === 'оплачено') {
              $cls = 'spot-paid';
              $titleAttr = 'Оплачено';
          } else {
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
        <?php endif; ?>
      ><?= (int)$spotNum ?></button>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

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
