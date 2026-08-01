<?php
/**
 * includes/log-reader.php
 * Чтение и разбор лога заявок (zayavki.log) для админ-панели:
 * сопоставляет оплаченные/неоплаченные машино-места по каждой
 * парковке за выбранный месяц.
 *
 * Формат строки лога (одна заявка — одна строка JSON) в файле
 * zayavki-<год>.log, см. includes/form-handler.php и result.php
 * (обработчик ResultURL-уведомлений Robokassa, который дописывает
 * payment_id и меняет status на "оплачено"):
 *   {"date":"...", "fio":"...", "phone":"...", "parking":"Парковка «Левитан»",
 *    "spot":"2", "month":"Январь", "amount":12000, "tariff":"...",
 *    "payment_id":"...", "status":"оплачено"}
 */

/**
 * Читает лог заявок и возвращает записи.
 *
 * Лог ротируется по годам: zayavki-2026.log, zayavki-2027.log и т.д.
 * (см. includes/form-handler.php) — при ~500 местах на 12 месяцев это
 * несколько тысяч строк в год, поэтому один файл на всё время работы
 * сайта был бы слишком большим и его неудобно архивировать.
 *
 * Админка (admin/index.php) всегда показывает статус ровно за ОДИН
 * выбранный месяц — значит и читать нужно только файл ТОГО года, к
 * которому этот месяц относится, а не всю историю сайта. Поэтому
 * функция принимает необязательный параметр $year: если он передан,
 * читается только zayavki-<year>.log (+ legacy-файл без года, если
 * остался с версии до введения ротации — он один на всё время и
 * дальше не растёт, поэтому читать его всегда безопасно). Так объём
 * чтения на каждой загрузке админки не зависит от того, сколько лет
 * подряд уже работает сайт — он ограничен данными одного года.
 *
 * Если $year не передан — читаются ВСЕ годовые файлы (используется
 * только там, где реально нужна полная история, если такое
 * понадобится в будущем; в текущей админке не используется).
 *
 * Файлы читаются в хронологическом порядке (сначала старый
 * нередактированный zayavki.log, если он ещё остался с прошлых
 * версий, затем zayavki-YYYY.log по возрастанию года) — это важно,
 * т.к. getSpotStatusesForMonth() считает актуальной последнюю
 * встреченную запись по месту+месяцу, а resolveSpotDisplay() смотрит
 * на ВСЕ записи по месту+месяцу (в т.ч. чтобы найти дубли оплаты).
 * Повреждённые/нераспознанные строки молча пропускаются, чтобы
 * одна битая строка не ломала всю админку.
 */
function loadZayavkiLog(?string $year = null): array {
    if (!defined('LOGS_PATH')) {
        return [];
    }

    $files = [];

    // Старый файл без ротации (мог остаться с версии до введения
    // ротации по годам) — читаем первым, в нём самые ранние записи.
    // Он конечен и не растёт дальше, поэтому включаем его всегда,
    // независимо от того, какой год запрошен.
    $legacy = LOGS_PATH . '/zayavki.log';
    if (is_readable($legacy)) {
        $files[] = $legacy;
    }

    if ($year !== null) {
        // Нужен только файл конкретного года — именно это и экономит
        // чтение при растущей многолетней истории заявок.
        $yearlyPath = LOGS_PATH . '/zayavki-' . $year . '.log';
        if (is_readable($yearlyPath)) {
            $files[] = $yearlyPath;
        }
    } else {
        // Годовые файлы: сортируем по имени — 'zayavki-2026.log' <
        // 'zayavki-2027.log' лексикографически совпадает с хронологией,
        // т.к. год всегда 4 цифры.
        $yearly = glob(LOGS_PATH . '/zayavki-*.log') ?: [];
        sort($yearly, SORT_STRING);
        $files = array_merge($files, $yearly);
    }

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
 * Возвращает ВСЕ заявки по каждому машино-месту за конкретный месяц
 * по каждой парковке:
 *   [
 *     'levitan' => [
 *        1 => [ ...заявка1..., ...заявка2... ],  // 0+ заявок за этот месяц,
 *        2 => [ ...заявка... ],                  // в хронологическом порядке
 *        3 => [],                                // пусто — заявок не было
 *        ...
 *     ],
 *     'kupelinka' => [...],
 *   ]
 *
 * Раньше эта функция схлопывала все заявки по месту+месяцу в одну
 * (побеждала последняя) — этого было достаточно для статуса "оплачено/
 * ожидает/пусто", но невозможно было заметить ситуацию, когда одно и
 * то же место случайно оплатили дважды (например, клиент дважды нажал
 * "Оплатить" или вручную перевёл деньги, уже имея заявку с успешной
 * онлайн-оплатой). Теперь функция сохраняет весь список заявок по
 * месту, а решение о том, что показывать (обычный статус или "дубль
 * оплаты"), принимает resolveSpotDisplay() ниже — так админка может
 * явно предупредить о повторной оплате и показать оба платежа.
 */
function getSpotStatusesForMonth(array $entries, array $parkings, string $month): array {
    $nameToKey = buildParkingNameToKeyMap($parkings);

    $result = [];
    foreach ($parkings as $key => [$name, $capacity]) {
        $result[$key] = array_fill(1, $capacity, []);
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
        $result[$key][$spot][] = $entry;
    }

    return $result;
}

/**
 * Определяет, как показать одно машино-место в админке, на основе
 * СПИСКА всех его заявок за месяц (см. getSpotStatusesForMonth()).
 *
 * Возвращает:
 *   [
 *     'state'       => 'empty' | 'paid' | 'pending' | 'duplicate',
 *     'entry'       => последняя заявка (для обычной карточки) | null,
 *     'paidEntries' => все заявки со статусом "оплачено" (для 'duplicate'),
 *   ]
 *
 * 'duplicate' — особый статус: заявок со статусом "оплачено" по этому
 * месту и месяцу ДВЕ и более. Это значит, что за одно и то же место
 * за один и тот же месяц прошло два (или больше) реальных платежа —
 * почти наверняка ошибка (двойное списание у клиента, дублирующая
 * заявка и т.п.), которую стоит проверить вручную и, возможно,
 * вернуть деньги за лишний платёж. Обычная ситуация "заявка создана
 * повторно, но оплачена лишь одна из них" под 'duplicate' не
 * попадает — там status = 'paid' по последней заявке, как и раньше.
 */
function resolveSpotDisplay(array $spotEntries): array {
    if (empty($spotEntries)) {
        return ['state' => 'empty', 'entry' => null, 'paidEntries' => []];
    }

    $paidEntries = array_values(array_filter(
        $spotEntries,
        fn($e) => ($e['status'] ?? '') === 'оплачено'
    ));

    if (count($paidEntries) >= 2) {
        return [
            'state'       => 'duplicate',
            'entry'       => end($spotEntries),
            'paidEntries' => $paidEntries,
        ];
    }

    $last = end($spotEntries);
    $state = (($last['status'] ?? '') === 'оплачено') ? 'paid' : 'pending';

    return ['state' => $state, 'entry' => $last, 'paidEntries' => $paidEntries];
}

/**
 * Короткая сводка по парковке за месяц: сколько мест оплачено /
 * ожидает оплаты (или другой незавершённый статус) / без заявок вовсе /
 * оплачено дважды и более (см. resolveSpotDisplay()).
 */
function summarizeParkingStatuses(array $spotStatuses): array {
    $summary = ['paid' => 0, 'pending' => 0, 'empty' => 0, 'duplicate' => 0, 'total' => count($spotStatuses)];
    foreach ($spotStatuses as $spotEntries) {
        $summary[resolveSpotDisplay($spotEntries)['state']]++;
    }
    return $summary;
}
