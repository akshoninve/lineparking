<?php
/**
 * includes/yookassa.php
 * Интеграция с платёжным шлюзом ЮKassa (https://yookassa.ru).
 *
 * Ключи YOOKASSA_SHOP_ID / YOOKASSA_SECRET_KEY / YOOKASSA_RETURN_URL
 * берутся из config.php, который лежит ВЫШЕ public_html
 * (см. подробный комментарий в самом config.php).
 *
 * ==================== КАК ПОДКЛЮЧИТЬ ОПЛАТУ ====================
 * 1. Дождитесь, пока магазин пройдёт модерацию в личном кабинете
 *    ЮKassa: https://yookassa.ru/my/settings/api-keys
 * 2. Скопируйте оттуда shopId и секретный ключ.
 * 3. Впишите их в /home/srv250266/config.php:
 *        define('YOOKASSA_SHOP_ID', 'ваш_shopId');
 *        define('YOOKASSA_SECRET_KEY', 'ваш_секретный_ключ');
 * 4. Больше ничего менять не нужно — форма на сайте сама начнёт
 *    переводить клиента на страницу оплаты картой.
 *
 * Пока поля пустые, сайт работает в резервном режиме: заявка
 * сохраняется в лог, а клиенту показываются реквизиты для оплаты
 * вручную (см. includes/form-handler.php и блок "Реквизиты" на сайте).
 * =================================================================
 */

/**
 * Создаёт платёж в ЮKassa и возвращает массив ['id' => ..., 'url' => ...],
 * либо null — если ключи ещё не подключены или произошла ошибка
 * (в том числе если ответ API пришёл в неожиданном формате).
 *
 * payment_id из ответа нужен, чтобы потом сопоставить заявку в логе
 * с уведомлением от ЮKassa (см. webhook.php и
 * includes/functions.php::updateZayavkaStatusByPaymentId()).
 *
 * При любой ошибке/неожиданном ответе пишет диагностику в
 * private/logs/yookassa-errors.log — см. logYookassaError() ниже —
 * чтобы можно было разобраться, что именно вернул API, не выключая
 * сайт целиком (form-handler.php в этом случае просто уходит в
 * резервный сценарий с ручными реквизитами вместо перехода на оплату).
 *
 * @param float  $amount      Сумма к оплате в рублях
 * @param string $description Описание платежа (видно клиенту и в кабинете ЮKassa)
 * @param array  $metadata    Произвольные данные заявки, которые ЮKassa вернёт вместе с платежом
 * @return array{id:string,url:string}|null
 */
function createYookassaPayment($amount, $description, $metadata) {
    if (YOOKASSA_SHOP_ID === '' || YOOKASSA_SECRET_KEY === '') {
        return null; // ключи ещё не подключены (магазин на модерации)
    }

    $payload = [
        'amount' => [
            'value'    => number_format($amount, 2, '.', ''),
            'currency' => 'RUB',
        ],
        'capture'      => true,
        'confirmation' => [
            'type'       => 'redirect',
            'return_url' => YOOKASSA_RETURN_URL,
        ],
        'description' => mb_substr($description, 0, 128),
        'metadata'    => $metadata,
    ];

    $ch = curl_init('https://api.yookassa.ru/v3/payments');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            // Idempotence-Key защищает от двойного списания при повторной отправке запроса
            'Idempotence-Key: ' . bin2hex(random_bytes(16)),
        ],
        CURLOPT_USERPWD => YOOKASSA_SHOP_ID . ':' . YOOKASSA_SECRET_KEY,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        logYookassaError('curl error: ' . $curlError, null);
        return null;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        logYookassaError("HTTP {$httpCode}", $response);
        return null;
    }

    $data = json_decode($response, true);
    $url  = $data['confirmation']['confirmation_url'] ?? null;
    $id   = $data['id'] ?? null;

    // Строго проверяем, что пришли и строка-ссылка, и id платежа.
    // Раньше здесь возвращался просто $url — если API вернул что-то
    // неожиданное, $url мог оказаться НЕ строкой, а form-handler.php
    // склеивал header('Location: ' . $paymentUrl), что PHP молча
    // превращало в буквальный текст "Array" (клиента уводило на
    // несуществующую страницу вместо оплаты). Явная проверка типов
    // устраняет саму возможность такой ошибки.
    if (!is_string($url) || $url === '' || !is_string($id) || $id === '') {
        logYookassaError('unexpected response shape (no confirmation_url/id)', $response);
        return null;
    }

    return ['id' => $id, 'url' => $url];
}

/**
 * Запрашивает у ЮKassa актуальное состояние платежа по его id.
 *
 * Используется вебхуком (webhook.php): статусу, который приходит
 * в теле уведомления, не доверяем вслепую — перепроверяем его
 * собственным GET-запросом к API. Это рекомендация из документации
 * ЮKassa, раздел "Входящие уведомления" → "Проверка подлинности
 * уведомлений" → "Проверка статуса объекта".
 *
 * @param string $paymentId Идентификатор платежа в ЮKassa
 * @return array|null Полный объект платежа (как в API) или null при ошибке
 */
function fetchYookassaPayment($paymentId) {
    if (YOOKASSA_SHOP_ID === '' || YOOKASSA_SECRET_KEY === '' || !$paymentId) {
        return null;
    }

    $ch = curl_init('https://api.yookassa.ru/v3/payments/' . urlencode($paymentId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => YOOKASSA_SHOP_ID . ':' . YOOKASSA_SECRET_KEY,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        logYookassaError('fetchYookassaPayment curl error: ' . $curlError, null);
        return null;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        logYookassaError("fetchYookassaPayment HTTP {$httpCode}", $response);
        return null;
    }
    return json_decode($response, true);
}

/**
 * Пишет диагностику неудачного/неожиданного ответа ЮKassa в
 * private/logs/yookassa-errors.log — отдельно от zayavki-*.log,
 * чтобы не путать заявки клиентов с технической отладкой API.
 */
function logYookassaError(string $context, ?string $rawResponse): void {
    if (!defined('LOGS_PATH')) {
        return;
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $context;
    if ($rawResponse !== null) {
        $line .= ' | response: ' . mb_substr($rawResponse, 0, 2000);
    }
    $line .= PHP_EOL;
    @file_put_contents(LOGS_PATH . '/yookassa-errors.log', $line, FILE_APPEND | LOCK_EX);
}
