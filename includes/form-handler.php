<?php
/**
 * includes/form-handler.php
 * Обработка отправленной формы оплаты (POST).
 *
 * Подключается из index.php ПОСЛЕ bootstrap.php — поэтому здесь уже
 * доступны $parkings, $months, $pricePerMonth и т.д. (parkings-data.php),
 * а также функции isLevitanPremiumSpot() (functions.php) и
 * createYookassaPayment() (yookassa.php).
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

// Пользователь вернулся со страницы оплаты ЮKassa
$returnedFromPayment = isset($_GET['payment']) && $_GET['payment'] === 'return';

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
    if (!preg_match('/^[\d\s\+\-\(\)]{10,20}$/u', $submitted['phone'])) {
        $errors['phone'] = 'Проверьте номер телефона.';
    }
    if (!array_key_exists($submitted['parking'], $parkings)) {
        $errors['parking'] = 'Выберите парковку.';
    }
    if ($submitted['spot'] === '' || !preg_match('/^\d{1,4}$/', $submitted['spot'])) {
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
        $tariffText  = number_format($amount, 0, ',', ' ') . ' ₽' . ($isPremium ? ' (место повышенной категории)' : '');
        $parkingName = $parkings[$submitted['parking']][0];

        // Пытаемся создать платёж в ЮKassa ДО записи в лог — так в
        // логе сразу будет payment_id, по которому webhook.php потом
        // найдёт эту заявку и проставит финальный статус оплаты
        // (см. includes/functions.php::updateZayavkaStatusByPaymentId()).
        $description = "Машино-место №{$submitted['spot']}, {$parkingName}, {$submitted['month']}";
        $metadata = [
            'fio' => $submitted['fio'], 'phone' => $submitted['phone'],
            'parking' => $parkingName, 'spot' => $submitted['spot'], 'month' => $submitted['month'],
        ];
        $payment = createYookassaPayment($amount, $description, $metadata);

        $entry = [
            'date'       => date('Y-m-d H:i:s'),
            'fio'        => $submitted['fio'],
            'phone'      => $submitted['phone'],
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

        // Явная проверка типа/непустоты — если createYookassaPayment()
        // вернула не-массив с валидной строкой url (например, ключи ещё
        // не подключены — тогда она вернёт null), просто уходим в
        // резервный сценарий ниже, а не пытаемся редиректить на мусор.
        if ($payment && is_string($payment['url'] ?? null) && $payment['url'] !== '') {
            header('Location: ' . $payment['url']);
            exit;
        }

        // ЮKassa ещё не подключена (идёт модерация) или произошла ошибка — резервный сценарий
        $paymentUnavailable = true;
        $success            = true;
        $lastTariff         = $entry['tariff'];
        $submitted = ['fio' => '', 'phone' => '', 'parking' => '', 'spot' => '', 'month' => '', 'agree' => ''];
    }
}
