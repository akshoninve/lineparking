<?php
/**
 * webhook.php
 * Приём HTTP-уведомлений от ЮKassa об изменении статуса платежа.
 *
 * Указать в личном кабинете ЮKassa:
 *   Интеграция → HTTP-уведомления → URL:
 *   https://xn--80aaaxdesfic0ah2j.xn--p1ai/webhook.php
 *   (punycode того же кириллического домена, что и в YOOKASSA_RETURN_URL)
 * События: payment.succeeded, payment.waiting_for_capture,
 *          payment.canceled, refund.succeeded
 *
 * См. документацию ЮKassa, раздел "Входящие уведомления".
 */
require_once __DIR__ . '/bootstrap.php';

// Первый уровень защиты — проверка, что запрос пришёл с IP ЮKassa.
// Список IPv4-подсетей из документации (см. "Проверка подлинности
// уведомлений" → "Проверка IP-адреса"). IPv6-подсеть 2a02:5180::/32
// сюда намеренно не включена — основная защита ниже (перепроверка
// платежа собственным запросом к API), поэтому отсутствие проверки
// IPv6 не открывает уязвимость.
$allowedRanges = [
    '185.71.76.0/27',
    '185.71.77.0/27',
    '77.75.153.0/25',
    '77.75.156.11/32',
    '77.75.156.35/32',
    '77.75.154.128/25',
];

function ipInRange($ip, $range) {
    if (strpos($ip, ':') !== false) return false; // IPv6 — не проверяем в этой упрощённой функции
    [$subnet, $bits] = explode('/', $range);
    $mask = -1 << (32 - (int)$bits);
    return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
}

$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$ipOk = false;
foreach ($allowedRanges as $range) {
    if (ipInRange($remoteIp, $range)) { $ipOk = true; break; }
}
if (!$ipOk) {
    http_response_code(403);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || ($data['type'] ?? '') !== 'notification' || empty($data['event']) || empty($data['object']['id'])) {
    http_response_code(400);
    exit;
}

$paymentId = $data['object']['id'];

// Второй, главный уровень защиты — не доверяем статусу из тела
// уведомления, а перепроверяем платёж собственным GET-запросом
// к API ЮKassa (рекомендация из документации: "Проверка статуса
// объекта"). Так поддельное уведомление ничего изменить не сможет,
// даже если IP-проверку выше кто-то обойдёт.
$payment = fetchYookassaPayment($paymentId);

if ($payment && ($payment['id'] ?? null) === $paymentId) {
    $statusMap = [
        'succeeded'           => 'оплачено',
        'canceled'            => 'отменено',
        'waiting_for_capture' => 'ожидает подтверждения',
    ];
    $realStatus = $payment['status'] ?? null;
    if (isset($statusMap[$realStatus])) {
        updateZayavkaStatusByPaymentId($paymentId, $statusMap[$realStatus]);
    }
}

// Подтверждаем получение кодом 200 в любом случае — иначе ЮKassa
// будет повторять доставку уведомления в течение 24 часов.
http_response_code(200);