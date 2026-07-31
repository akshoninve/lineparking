<?php
/**
 * result.php
 * Приём серверных уведомлений Robokassa об оплате (ResultURL).
 *
 * Указать в личном кабинете Robokassa → Технические настройки магазина:
 *   ResultURL:
 *   https://xn--80aaaxdesfic0ah2j.xn--p1ai/result.php
 *   (punycode того же кириллического домена, что и в остальных URL сайта)
 *   Метод запроса — POST (Robokassa по умолчанию шлёт POST, но код ниже
 *   на всякий случай понимает и GET).
 *
 * См. документацию Robokassa, раздел "Интерфейс оплаты" →
 * "Типовая последовательность работы": "Магазин получает уведомление
 * на ResultURL и самостоятельно обновляет статус заказа" — Robokassa
 * не меняет статус сама, это обязана сделать наша сторона.
 *
 * ВАЖНО и это ключевое отличие от вебхука ЮKassa: Robokassa ждёт от
 * ResultURL не просто HTTP 200, а тело ответа строго вида "OK" + InvId
 * (например "OK12345"), без лишних символов/пробелов/HTML. Если этого
 * текста нет — Robokassa считает уведомление недоставленным и будет
 * повторять его позже.
 *
 * Единственная защита от поддельных уведомлений здесь — проверка
 * подписи (SignatureValue), которая считается с использованием
 * Пароля #2 (см. includes/robokassa.php::robokassaVerifyResultSignature()).
 * У Robokassa, в отличие от ЮKassa, нет отдельного API-метода, чтобы
 * перепроверить платёж встречным запросом, — подпись это и единственный,
 * и рекомендованный производителем способ убедиться в подлинности.
 */
require_once __DIR__ . '/bootstrap.php';

// Robokassa по умолчанию шлёт POST, но допускает и GET — читаем то,
// что пришло.
$data = $_POST ?: $_GET;

$outSum    = $data['OutSum'] ?? null;
$invId     = $data['InvId'] ?? null;
$signature = $data['SignatureValue'] ?? null;

if ($outSum === null || $invId === null || $signature === null) {
    http_response_code(400);
    exit;
}

if (!robokassaVerifyResultSignature($outSum, $invId, $signature)) {
    // Неверная подпись — не трогаем лог заявок и не подтверждаем приём.
    http_response_code(400);
    exit('bad sign');
}

// Подпись верна — считаем оплату подтверждённой и проставляем статус
// по InvId (он же хранится в логе в поле payment_id, см.
// includes/form-handler.php).
updateZayavkaStatusByPaymentId((string)$invId, 'оплачено');

// Обязательный формат ответа для Robokassa: "OK" + InvId, без переносов
// строк и другого текста вокруг.
echo 'OK' . $invId;
