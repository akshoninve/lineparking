<?php
/**
 * includes/functions.php
 * Вспомогательные функции общего назначения, не привязанные
 * к конкретным данным (данные парковок — в parkings-data.php).
 */

/**
 * Автоматический cache-busting для статики (CSS/JS): дописывает к
 * ссылке "?v=<время последнего изменения файла>". Каждый раз, когда
 * вы заливаете новую версию assets/css/style.css или assets/js/main.js
 * на сервер, у файла меняется mtime — значит меняется и ?v=..., значит
 * браузер видит "новый" URL и обязан скачать файл заново, а не отдать
 * из своего кэша. Ничего вручную обновлять (версию, номер сборки)
 * не нужно — она всегда берётся из самой файловой системы.
 *
 * Раньше при заливке новой версии CSS/JS через FTP браузер клиента мог
 * долго показывать старую версию из своего кэша (именно так объяснялась
 * ситуация в переписке: новая HTML-разметка уже на сервере, а старые
 * стили/скрипт ещё в кэше телефона — итог выглядел как сломанная
 * вёрстка). После этого изменения URL меняется при каждой заливке
 * автоматически, поэтому такая рассинхронизация станет невозможна —
 * пересобирать кэш вручную (Ctrl+F5 и т.п.) для новых посетителей
 * больше не потребуется, это нужно только сейчас, один раз, чтобы
 * увидеть уже выложенную сегодня версию.
 *
 * @param string $urlPath      Путь для href/src, ОТНОСИТЕЛЬНО ТЕКУЩЕЙ
 *                              страницы (например "../assets/css/admin.css"
 *                              для страниц из подпапки admin/) — именно
 *                              он и попадёт в разметку как есть, версия
 *                              лишь дописывается в конец.
 * @param string $fsRelativePath Тот же файл, но путём ОТНОСИТЕЛЬНО КОРНЯ
 *                              сайта (PUBLIC_PATH) — нужен только чтобы
 *                              найти файл на диске и прочитать его mtime,
 *                              на итоговый URL не влияет.
 */
function assetVersion(string $urlPath, string $fsRelativePath): string {
    if (!defined('PUBLIC_PATH')) {
        return $urlPath;
    }
    $absolute = PUBLIC_PATH . '/' . ltrim($fsRelativePath, '/');
    $mtime = @filemtime($absolute);
    return $urlPath . ($mtime ? '?v=' . $mtime : '');
}

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
 * Вызывается из result.php после получения и проверки подписи
 * ResultURL-уведомления от Robokassa об оплате.
 *
 * @param string $paymentId Идентификатор платежа (InvId в Robokassa)
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

/**
 * Считает стоимость аренды за произвольный период в днях — период
 * МОЖЕТ пересекать границу одного или нескольких календарных месяцев
 * (и даже год), это ОДИН платёж, без разбивки на несколько заявок.
 *
 * Дневная ставка внутри каждого месяца своя: месячный тариф / число
 * дней В ЭТОМ КОНКРЕТНОМ месяце (28/29/30/31 — через DateTime::format('t')).
 * Поэтому период режется на "отрезки" по календарным месяцам — например,
 * "28 сентября – 3 октября" превращается в отрезок "28–30 сентября"
 * (3 дня по сентябрьской ставке) + отрезок "1–3 октября" (3 дня по
 * октябрьской ставке), а итоговая сумма — сумма всех отрезков. Так
 * стоимость суток корректна в каждом месяце, а полный месяц днями
 * по-прежнему совпадает день-в-день с обычным месячным тарифом.
 *
 * Округление до целого рубля — один раз, по ИТОГОВОЙ сумме всех
 * отрезков (не по каждому отрезку отдельно) — так меньше накопленная
 * погрешность округления на длинных периодах из нескольких отрезков.
 * Округляем вверх (в пользу компании), чтобы никогда не терять копейки.
 *
 * @param string $dateFrom     'Y-m-d'
 * @param string $dateTo       'Y-m-d', включительно
 * @param float  $monthlyPrice Месячный тариф (базовый или премиальный)
 * @return array{days:int, total:int, segments:array} segments — по одному
 *         элементу на каждый затронутый календарный месяц:
 *         {label:string ("Сентябрь 2026"), days:int, daysInMonth:int, perDay:float, amount:int}
 *         — пригодится для подробной расшифровки в письме/чеке/админке.
 */
function calculatePartialPeriodPrice(string $dateFrom, string $dateTo, float $monthlyPrice): array {
    static $monthNamesNom = [
        'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
    ];

    $to = new DateTime($dateTo);

    $segments    = [];
    $totalDays   = 0;
    $totalAmount = 0.0; // копим точную (нецелую) сумму, округляем один раз в конце

    $cursor = new DateTime($dateFrom);
    while ($cursor <= $to) {
        $monthEnd    = (clone $cursor)->modify('last day of this month');
        $segmentEnd  = $monthEnd < $to ? $monthEnd : clone $to;

        $daysInMonth  = (int)$cursor->format('t');
        $segmentDays  = $cursor->diff($segmentEnd)->days + 1;
        $perDay       = $monthlyPrice / $daysInMonth;
        $segmentFloat = $perDay * $segmentDays;

        $segments[] = [
            'label'       => $monthNamesNom[(int)$cursor->format('n') - 1] . ' ' . $cursor->format('Y'),
            'days'        => $segmentDays,
            'daysInMonth' => $daysInMonth,
            'perDay'      => round($perDay, 2),
            'amount'      => (int)round($segmentFloat), // для отображения по отрезку; итог считаем от $totalAmount
        ];

        $totalDays   += $segmentDays;
        $totalAmount += $segmentFloat;

        $cursor = (clone $segmentEnd)->modify('+1 day');
    }

    return [
        'days'     => $totalDays,
        'total'    => (int)ceil($totalAmount),
        'segments' => $segments,
    ];
}

/**
 * Человекочитаемый текст периода для чека Robokassa, письма и лога —
 * компактный числовой формат "с ДД.ММ.ГГ по ДД.ММ.ГГ":
 *   один день:      "04.06.26"
 *   период:          "с 04.06.26 по 06.09.26"
 * Числовой формат выбран специально: он одинаково короткий что для
 * периода внутри месяца, что для периода на стыке месяцев и лет — не
 * нужно отдельно решать, писать ли год/месяц у обеих дат (как было бы
 * с текстовыми названиями месяцев).
 */
function periodDatesToText(string $dateFrom, string $dateTo): string {
    $from = new DateTime($dateFrom);
    $to   = new DateTime($dateTo);

    if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
        return $from->format('d.m.y');
    }

    return 'с ' . $from->format('d.m.y') . ' по ' . $to->format('d.m.y');
}

/**
 * Список "Месяц Год" (в формате $months из parkings-data.php, например
 * "Сентябрь 2026"), которые затрагивает период — один элемент на
 * каждый календарный месяц, через который проходит период. Нужен,
 * чтобы записать в лог entry['months'] и тем самым "забронировать"
 * место сразу во всех затронутых месяцах шахматки в админке (см.
 * includes/log-reader.php) — иначе, скажем, октябрьская часть периода
 * "28 сентября – 3 октября" была бы не видна в шахматке за октябрь,
 * и место могло бы случайно уйти второй заявке.
 *
 * @return string[]
 */
function monthLabelsForPeriod(string $dateFrom, string $dateTo): array {
    static $monthNamesNom = [
        'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
    ];

    $to = new DateTime($dateTo);
    $cursor = new DateTime($dateFrom);
    $cursor->modify('first day of this month');

    $labels = [];
    while ($cursor <= $to) {
        $labels[] = $monthNamesNom[(int)$cursor->format('n') - 1] . ' ' . $cursor->format('Y');
        $cursor->modify('first day of next month');
    }
    return $labels;
}

/**
 * Приводит телефон к единому формату для хранения в логе/базе:
 * "+79026001338", без пробелов, скобок и дефисов.
 *
 * В форму (assets/js/main.js) номер вводится с маской вида
 * "+7 900 000-00-00" — это удобно для человека при вводе, но такой
 * формат неудобно потом искать/сравнивать в логах. Поэтому в
 * $entry['phone'] (includes/form-handler.php) должен попадать уже
 * нормализованный номер, а маска остаётся только визуальным
 * оформлением поля на странице.
 *
 * Логика такая же, как в валидации телефона (form-handler.php):
 * 10 цифр — российский номер без кода страны, добавляем "7" в начало;
 * 11 цифр, начинающихся на "8" — заменяем "8" на "7"; 11 цифр,
 * начинающихся на "7" — оставляем как есть. Во всех случаях перед
 * итоговыми цифрами добавляется "+".
 */
function normalizeRussianPhone(string $raw): string {
    $digits = preg_replace('/\D/', '', $raw);
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '7' . $digits;
    }
    return '+' . $digits;
}
