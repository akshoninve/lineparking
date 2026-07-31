<?php
/**
 * includes/log-reader.php
 * Чтение и разбор лога заявок (zayavki.log) для админ-панели:
 * сопоставляет оплаченные/неоплаченные машино-места по каждой
 * парковке за выбранный месяц.
 *
 * Формат строки лога (одна заявка — одна строка JSON) в файле
 * zayavki-<год>.log, см. includes/form-handler.php и обработчик
 * уведомлений ЮKassa, который дописывает payment_id и меняет
 * status на "оплачено":
 *   {"date":"...", "fio":"...", "phone":"...", "parking":"Парковка «Левитан»",
 *    "spot":"2", "month":"Январь", "amount":12000, "tariff":"...",
 *    "payment_id":"...", "status":"оплачено"}
 */

/**
 * Читает лог заявок и возвращает все записи (объединяя все файлы).
 * Лог ротируется по годам: zayavki-2026.log, zayavki-2027.log и т.д.
 * (см. includes/form-handler.php) — при ~260 местах на 12 месяцев это
 * ~3000+ строк в год, поэтому один файл на всё время работы сайта
 * был бы слишком большим и его неудобно архивировать.
 *
 * Файлы читаются в хронологическом порядке (сначала старый
 * нередактированный zayavki.log, если он ещё остался с прошлых
 * версий, затем zayavki-YYYY.log по возрастанию года) — это важно,
 * т.к. getSpotStatusesForMonth() считает актуальной последнюю
 * встреченную запись по месту+месяцу.
 * Повреждённые/нераспознанные строки молча пропускаются, чтобы
 * одна битая строка не ломала всю админку.
 */
function loadZayavkiLog(): array {
    if (!defined('LOGS_PATH')) {
        return [];
    }

    $files = [];

    // Старый файл без ротации (мог остаться с версии до введения
    // ротации по годам) — читаем первым, в нём самые ранние записи.
    $legacy = LOGS_PATH . '/zayavki.log';
    if (is_readable($legacy)) {
        $files[] = $legacy;
    }

    // Годовые файлы: сортируем по имени — 'zayavki-2026.log' <
    // 'zayavki-2027.log' лексикографически совпадает с хронологией,
    // т.к. год всегда 4 цифры.
    $yearly = glob(LOGS_PATH . '/zayavki-*.log') ?: [];
    sort($yearly, SORT_STRING);
    $files = array_merge($files, $yearly);

    $entries = [];
    foreach ($files as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
    }
    return $entries;
}

/**
 * Карта "отображаемое имя парковки" => "ключ парковки" ('levitan' и т.д.),
 * т.к. в логе хранится имя (form-handler.php пишет $parkings[$key][0]),
 * а не сам ключ.
 */
function buildParkingNameToKeyMap(array $parkings): array {
    $map = [];
    foreach ($parkings as $key => [$name, $capacity]) {
        $map[$name] = $key;
    }
    return $map;
}

/**
 * Возвращает статус каждого машино-места за конкретный месяц по каждой парковке:
 *   [
 *     'levitan' => [
 *        1 => [...запись из лога...] | null,   // null — заявок за этот месяц не было
 *        2 => [...],
 *        ...
 *     ],
 *     'nesterov' => [...],
 *     'kupelinka' => [...],
 *   ]
 * Если по месту+месяцу несколько записей в логе — побеждает последняя
 * (самая свежая по порядку добавления, лог только дописывается в конец).
 */
function getSpotStatusesForMonth(array $entries, array $parkings, string $month): array {
    $nameToKey = buildParkingNameToKeyMap($parkings);

    $result = [];
    foreach ($parkings as $key => [$name, $capacity]) {
        $result[$key] = array_fill(1, $capacity, null);
    }

    foreach ($entries as $entry) {
        if (($entry['month'] ?? null) !== $month) {
            continue;
        }
        $key = $nameToKey[$entry['parking'] ?? ''] ?? null;
        if ($key === null) {
            continue;
        }
        $spot = (int)($entry['spot'] ?? 0);
        if ($spot < 1 || !array_key_exists($spot, $result[$key])) {
            continue;
        }
        $result[$key][$spot] = $entry;
    }

    return $result;
}

/**
 * Короткая сводка по парковке за месяц: сколько мест оплачено /
 * ожидает оплаты (или другой незавершённый статус) / без заявок вовсе.
 */
function summarizeParkingStatuses(array $spotStatuses): array {
    $summary = ['paid' => 0, 'pending' => 0, 'empty' => 0, 'total' => count($spotStatuses)];
    foreach ($spotStatuses as $entry) {
        if ($entry === null) {
            $summary['empty']++;
        } elseif (($entry['status'] ?? '') === 'оплачено') {
            $summary['paid']++;
        } else {
            $summary['pending']++;
        }
    }
    return $summary;
}
