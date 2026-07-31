<?php
/**
 * result.php
 * Приём серверных уведомлений Robokassa об оплате (ResultURL).
 *
 * Указать в личном кабинете Robokassa → Технические настройки магазина:
 *   ResultURL: https://лайнпаркинг.рф/result.php (в punycode)
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
 *
 * ДИАГНОСТИКА: пока интеграция отлаживается, каждое обращение сюда
 * (успешное и с ошибкой) логируется в private/logs/robokassa-result.log
 * через logRobokassaResult() — это единственный способ увидеть, доходят
 * ли уведомления от Robokassa до сервера и что именно в них приходит.
 */
require_once __DIR__ . '/bootstrap.php';

// Robokassa по умолчанию шлёт POST, но допускает и GET — читаем то,
// что пришло.
$data = $_POST ?: $_GET;

$outSum    = $data['OutSum'] ?? null;
$invId     = $data['InvId'] ?? null;
$signature = $data['SignatureValue'] ?? null;

if ($outSum === null || $invId === null || $signature === null) {
    logRobokassaResult('missing_params', $data);
    http_response_code(400);
    exit;
}

if (!robokassaVerifyResultSignature($outSum, $invId, $signature)) {
    // Неверная подпись — не трогаем лог заявок и не подтверждаем приём.
    logRobokassaResult('bad_signature', $data);
    http_response_code(400);
    exit('bad sign');
}

// Подпись верна — считаем оплату подтверждённой и проставляем статус
// по InvId (он же хранится в логе в поле payment_id, см.
// includes/form-handler.php).
$updated = updateZayavkaStatusByPaymentId((string)$invId, 'оплачено');
logRobokassaResult($updated ? 'ok_updated' : 'ok_but_not_found', $data);

// Обязательный формат ответа для Robokassa: "OK" + InvId, без переносов
// строк и другого текста вокруг.
echo 'OK' . $invId;
