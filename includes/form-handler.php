<?php
/**
 * includes/form-handler.php
 * Обработка отправленной формы оплаты (POST).
 *
 * Подключается из index.php ПОСЛЕ bootstrap.php — поэтому здесь уже
 * доступны $parkings, $months, $pricePerMonth и т.д. (parkings-data.php),
 * а также функции isLevitanPremiumSpot() (functions.php) и
 * createRobokassaPayment() (robokassa.php).
 *
 * Результат работы файла — набор переменных, которые использует
 * разметка в index.php: $errors, $success, $lastTariff, $paymentUrl,
 * $paymentUnavailable, $submitted, $returnedFromPayment.
 */

$errors             = [];
$success            = false;
$lastTariff         = null;
$paymentUrl         = null;
$paymentUnavailable = false;
$submitted = [
    'fio' => '', 'phone' => '', 'parking' => '', 'spot' => '', 'month' => '', 'agree' => '',
];

// Пользователь вернулся со страницы оплаты Robokassa.
//
// Success URL / Fail URL в кабинете Robokassa указывают просто на
// корень сайта (без query-параметров) с методом GET — это требование
// самой Robokassa: при методе GET она сама допишет к URL свои
// параметры (OutSum, InvId, SignatureValue), а собственный маркер
// вида ?payment=return в самом URL Robokassa запрещает добавлять.
// Поэтому вместо чтения такого маркера проверяем подпись параметров,
// которые Robokassa подставляет сама (см.
// includes/robokassa.php::robokassaVerifyReturnSignature()) — так
// мы надёжно отличаем "человек вернулся со страницы Robokassa" от
// обычного захода на главную. Старый маркер ?payment=return тоже
// продолжает поддерживаться — на случай, если он где-то ещё
// используется (например, в сохранённой ссылке).
$returnedFromPayment = (isset($_GET['payment']) && $_GET['payment'] === 'return')
    || (isset($_GET['OutSum'], $_GET['InvId'], $_GET['SignatureValue'])
        && robokassaVerifyReturnSignature($_GET['OutSum'], $_GET['InvId'], $_GET['SignatureValue']));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_request'])) {

    $submitted['fio']     = trim($_POST['fio'] ?? '');
    $submitted['phone']   = trim($_POST['phone'] ?? '');
    $submitted['parking'] = trim($_POST['parking'] ?? '');
    $submitted['spot']    = trim($_POST['spot'] ?? '');
    $submitted['month']   = trim($_POST['month'] ?? '');
    $submitted['agree']   = isset($_POST['agree']) ? '1' : '';

    if (mb_strlen($submitted['fio']) < 3) {
        $errors['fio'] = 'Укажите фамилию, имя и отчество полностью.';
    }

    // Проверяем телефон по количеству цифр, а не по формату записи —
    // так номер проходит независимо от того, как именно он введён:
    // "+7 900 000-00-00", "8(900)000-00-00", "79000000000" и т.п.
    // Российский номер — 10 цифр без кода страны или 11 цифр с ним
    // (7XXXXXXXXXX / 8XXXXXXXXXX), поэтому допустимый диапазон 10–11.
    $phoneDigits = preg_replace('/\D/', '', $submitted['phone']);
    if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 11) {
        $errors['phone'] = 'Проверьте номер телефона.';
    }
    // Нормализованный телефон для записи в лог/письмо — без пробелов
    // и дефисов, в формате "+79026001338" (см. functions.php).
    // Пользователю в поле формы при ошибке всё равно показываем
    // $submitted['phone'] как он его ввёл — это не меняем.
    $normalizedPhone = normalizeRussianPhone($submitted['phone']);

    if (!array_key_exists($submitted['parking'], $parkings)) {
        $errors['parking'] = 'Выберите парковку.';
    }
    // Номер машино-места — 1–3 цифры (макс. вместимость парковок сейчас
    // 132 места, см. includes/parkings-data.php), было 1–4.
    if ($submitted['spot'] === '' || !preg_match('/^\d{1,3}$/', $submitted['spot'])) {
        $errors['spot'] = 'Укажите номер машино-места (только цифры).';
    }
    if (!in_array($submitted['month'], $months, true)) {
        $errors['month'] = 'Выберите месяц оплаты.';
    }
    if ($submitted['agree'] !== '1') {
        $errors['agree'] = 'Нужно принять условия публичной оферты.';
    }

    if (empty($errors)) {
        $isPremium   = $submitted['parking'] === 'levitan' && isLevitanPremiumSpot($submitted['spot'], $levitanPremiumRanges);
        $amount      = $isPremium ? $levitanPremiumPrice : $pricePerMonth;
        $tariffText  = number_format($amount, 0, ',', ' ') . ' ₽';
        $parkingName = $parkings[$submitted['parking']][0];

        // Готовим номер счёта (InvId) и формируем платёжную ссылку
        // Robokassa ДО записи в лог — так в логе сразу будет payment_id
        // (= InvId Robokassa), по которому result.php потом найдёт эту
        // заявку и проставит финальный статус оплаты (см.
        // includes/functions.php::updateZayavkaStatusByPaymentId()).
        // В отличие от ЮKassa здесь нет сетевого запроса на создание
        // платежа — ссылка с подписью формируется локально (см.
        // includes/robokassa.php::createRobokassaPayment()).
        $description = "Машино-место №{$submitted['spot']}, {$parkingName}, {$submitted['month']}";
        $invId       = nextRobokassaInvId();
        $payment     = $invId !== null ? createRobokassaPayment($amount, $description, $invId) : null;

        $entry = [
            'date'       => date('Y-m-d H:i:s'),
            'fio'        => $submitted['fio'],
            'phone'      => $normalizedPhone,
            'parking'    => $parkingName,
            'spot'       => $submitted['spot'],
            'month'      => $submitted['month'],
            'amount'     => $amount,
            'tariff'     => $tariffText,
            'payment_id' => $payment['id'] ?? null,
            'status'     => 'ожидает оплаты',
        ];

        // ВАЖНО: лог заявок пишем ВНЕ public_html — в /home/srv250266/private/logs/.
        // Раньше файл zayavki.log лежал прямо в public_html, а значит был
        // доступен любому по прямой ссылке вида https://.../zayavki.log —
        // там строка за строкой лежат ФИО и телефоны клиентов. Вне
        // public_html файл физически недоступен через браузер.
        //
        // Лог ротируется по годам: zayavki-2026.log, zayavki-2027.log и т.д.
        // При ~260 местах на 12 месяцев это ~3000+ строк в год — один файл
        // на всё время работы сайта стал бы слишком большим и неудобным
        // для архивации. Старые годы можно спокойно архивировать/убирать
        // отдельно, не трогая текущий файл. Чтение (includes/log-reader.php,
        // используется в админке) объединяет все такие файлы автоматически,
        // а обновление статуса по payment_id (functions.php) само находит
        // нужный годовой файл — см. комментарий там.
        $logPath = LOGS_PATH . '/zayavki-' . date('Y') . '.log';
        $logLine = json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);

        // Необязательное уведомление на почту (сработает, только если на сервере настроен sendmail)
        $subject = '=?UTF-8?B?' . base64_encode('Новая заявка на оплату — ЛАЙНПАРКИНГ') . '?=';
        $body = "ФИО: {$entry['fio']}\nТелефон: {$entry['phone']}\nПарковка: {$entry['parking']}\nМесто: {$entry['spot']}\nМесяц оплаты: {$entry['month']}\nТариф: {$entry['tariff']}\nДата заявки: {$entry['date']}";
        $headers = "Content-Type: text/plain; charset=UTF-8\r\nFrom: ЛайнПаркинг <noreply@лайнпаркинг.рф>\r\n";
        @mail(NOTIFY_EMAIL, $subject, $body, $headers);

        // Явная проверка типа/непустоты — если createRobokassaPayment()
        // вернула не-массив с валидной строкой url (например, ключи ещё
        // не подключены — тогда она вернёт null), просто уходим в
        // резервный сценарий ниже, а не пытаемся редиректить на мусор.
        if ($payment && is_string($payment['url'] ?? null) && $payment['url'] !== '') {
            header('Location: ' . $payment['url']);
            exit;
        }

        // Robokassa ещё не подключена (не вписаны ключи) или не удалось
        // получить InvId — резервный сценарий
        $paymentUnavailable = true;
        $success            = true;
        $lastTariff         = $entry['tariff'];
        $submitted = ['fio' => '', 'phone' => '', 'parking' => '', 'spot' => '', 'month' => '', 'agree' => ''];
    }
}
