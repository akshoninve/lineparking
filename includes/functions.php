<?php
/**
 * includes/functions.php
 * Вспомогательные функции общего назначения, не привязанные
 * к конкретным данным (данные парковок — в parkings-data.php).
 */

/**
 * Проверяет, входит ли номер машино-места в список мест повышенной
 * категории (массив диапазонов вида [[1,8],[54,66], ...]).
 */
function isLevitanPremiumSpot($spot, $ranges) {
    $n = (int)$spot;
    foreach ($ranges as [$from, $to]) {
        if ($n >= $from && $n <= $to) {
            return true;
        }
    }
    return false;
}

/**
 * Собирает диапазоны мест в текст вида "№1–8, №54–66, №124–132"
 * для вывода на странице и в оферте.
 */
function levitanPremiumRangesText($ranges) {
    return implode(', ', array_map(
        fn($r) => $r[0] === $r[1] ? "№{$r[0]}" : "№{$r[0]}–{$r[1]}",
        $ranges
    ));
}

/**
 * Находит запись с нужным payment_id и меняет её статус.
 *
 * Лог ротируется по годам (includes/form-handler.php пишет в
 * zayavki-<год>.log), поэтому заранее неизвестно, в каком именно
 * файле лежит нужная запись — перебираем все подходящие файлы
 * (сначала legacy zayavki.log, если остался, затем zayavki-YYYY.log
 * по убыванию года — новые заявки чаще ищутся, чем старые) и
 * останавливаемся на первом файле, где нашлось совпадение.
 *
 * Каждый файл — построчный JSON (JSON Lines), поэтому обновление
 * одной записи технически означает: прочитать все строки нужного
 * файла, поменять одну, перезаписать файл целиком под эксклюзивной
 * блокировкой (чтобы не столкнуться с одновременной записью новой
 * заявки из form-handler.php — она пишет только в файл ТЕКУЩЕГО
 * года, поэтому конфликт возможен только с ним).
 *
 * Вызывается из webhook.php после получения и проверки уведомления
 * от ЮKassa об изменении статуса платежа.
 *
 * @param string $paymentId Идентификатор платежа в ЮKassa
 * @param string $newStatus Новый статус для записи в лог, например 'оплачено'
 * @return bool true, если подходящая запись была найдена и обновлена
 */
function updateZayavkaStatusByPaymentId($paymentId, $newStatus) {
    if (!$paymentId || !defined('LOGS_PATH')) {
        return false;
    }

    $files = [];
    $legacy = LOGS_PATH . '/zayavki.log';
    if (is_file($legacy)) {
        $files[] = $legacy;
    }
    $yearly = glob(LOGS_PATH . '/zayavki-*.log') ?: [];
    rsort($yearly, SORT_STRING); // сначала более поздние годы — там чаще искомая запись
    $files = array_merge($files, $yearly);

    foreach ($files as $path) {
        if (updateZayavkaStatusInFile($path, $paymentId, $newStatus)) {
            return true;
        }
    }
    return false;
}

/**
 * Ищет и обновляет запись по payment_id в ОДНОМ конкретном файле лога.
 * Возвращает true, только если запись была найдена именно в этом файле.
 */
function updateZayavkaStatusInFile($path, $paymentId, $newStatus) {
    if (!is_file($path)) {
        return false;
    }

    $fp = fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return false;
    }

    $lines = [];
    $updated = false;
    while (($line = fgets($fp)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        $entry = json_decode($line, true);
        if (is_array($entry) && ($entry['payment_id'] ?? null) === $paymentId) {
            $entry['status'] = $newStatus;
            $updated = true;
        }
        $lines[] = json_encode($entry, JSON_UNESCAPED_UNICODE);
    }

    if ($updated) {
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $updated;
}
