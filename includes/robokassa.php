<?php
/**
 * includes/robokassa.php
 * Интеграция с платёжным шлюзом Robokassa (https://robokassa.ru).
 *
 * Ключи ROBOKASSA_MERCHANT_LOGIN / ROBOKASSA_PASSWORD1 / ROBOKASSA_PASSWORD2 /
 * ROBOKASSA_IS_TEST берутся из config.php, который лежит ВЫШЕ public_html
 * (см. подробный комментарий в самом config.php).
 *
 * В отличие от ЮKassa, Robokassa не требует похода в её API, чтобы
 * "создать" платёж: платёжная ссылка формируется полностью локально —
 * это URL страницы https://auth.robokassa.ru/Merchant/Index.aspx с
 * набором GET-параметров и подписью (SignatureValue), которую считаем
 * сами по формуле из документации Robokassa ("Интерфейс оплаты" →
 * "Сборка подписи SignatureValue"):
 *   MD5(MerchantLogin:OutSum:InvId:Пароль#1)
 *
 * ==================== КАК ПОДКЛЮЧИТЬ ОПЛАТУ ====================
 * 1. Зарегистрируйте магазин в личном кабинете Robokassa и укажите
 *    в Технических настройках:
 *      ResultURL:  https://лайнпаркинг.рф/result.php (в punycode),
 *                  метод — GET или POST, оба поддерживаются.
 *      SuccessURL: https://лайнпаркинг.рф/ (просто корень домена,
 *                  БЕЗ query-параметров — Robokassa при методе GET
 *                  сама допишет к нему свои параметры OutSum/InvId/
 *                  SignatureValue, поэтому наш собственный маркер
 *                  вида ?payment=return в самом URL Robokassa
 *                  запрещает, если метод GET). Метод — GET.
 *      FailURL:    так же, как SuccessURL.
 *    Корень домена открывает index.php стандартными настройками
 *    хостинга, так что дополнительно ничего создавать не нужно —
 *    возврат с оплаты обрабатывается прямо в index.php, см.
 *    robokassaVerifyReturnSignature() ниже и includes/form-handler.php.
 * 2. Скопируйте MerchantLogin (единый для теста и боя), Пароль №1 и
 *    Пароль №2 (для тестового режима — ОТДЕЛЬНУЮ тестовую пару из
 *    того же раздела Технических настроек, не боевую).
 * 3. Впишите их в /home/srv250266/config.php:
 *        define('ROBOKASSA_MERCHANT_LOGIN', 'ваш_логин');
 *        define('ROBOKASSA_PASSWORD1', 'тестовый_или_боевой_пароль_1');
 *        define('ROBOKASSA_PASSWORD2', 'тестовый_или_боевой_пароль_2');
 *        define('ROBOKASSA_IS_TEST', true); // true — тестовый режим, false — боевой
 * 4. Больше ничего менять не нужно — форма на сайте сама начнёт
 *    переводить клиента на страницу оплаты Robokassa.
 *
 * Пока ключи не вписаны, сайт работает в резервном режиме: заявка
 * сохраняется в лог, а клиенту показываются реквизиты для оплаты
 * вручную (см. includes/form-handler.php и блок "Реквизиты" на сайте).
 * =================================================================
 */

/**
 * Есть ли минимально необходимые ключи Robokassa для создания платежа.
 */
function robokassaConfigured(): bool {
    return defined('ROBOKASSA_MERCHANT_LOGIN') && defined('ROBOKASSA_PASSWORD1')
        && ROBOKASSA_MERCHANT_LOGIN !== '' && ROBOKASSA_PASSWORD1 !== '';
}

/**
 * Генерирует следующий уникальный номер счёта (InvId) для Robokassa.
 *
 * У Robokassa (в отличие от ЮKassa) нет своего API создания платежа,
 * которое возвращало бы готовый id, — идентификатор счёта задаём мы
 * сами и он должен быть уникальным для каждой оплаты (см. документацию
 * Robokassa, раздел "Интерфейс оплаты" → параметр InvId). Чтобы не
 * заводить БД ради одного счётчика, храним его в отдельном текстовом
 * файле рядом с логами заявок, с эксклюзивной блокировкой на случай
 * одновременных заявок.
 *
 * @return int|null Следующий InvId, либо null, если LOGS_PATH не определён
 *                   или файл счётчика не удалось открыть/заблокировать.
 */
function nextRobokassaInvId(): ?int {
    if (!defined('LOGS_PATH')) {
        return null;
    }
    $path = LOGS_PATH . '/robokassa-invid-counter.txt';
    $fp = fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return null;
    }
    $raw = stream_get_contents($fp);
    $current = (int)trim((string)$raw);
    $next = $current + 1;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, (string)$next);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $next;
}

/**
 * Приводит сумму к формату, который ожидает Robokassa: число через
 * точку, ровно 2 знака после запятой (например "12000.00").
 */
function formatRobokassaOutSum($amount): string {
    return number_format((float)$amount, 2, '.', '');
}

/**
 * Формирует платёжную ссылку Robokassa и возвращает массив
 * ['id' => <InvId как строка>, 'url' => <ссылка на оплату>],
 * либо null — если ключи ещё не подключены.
 *
 * Никакого сетевого запроса здесь не происходит (в отличие от
 * createYookassaPayment() в старой интеграции) — вся "магия" в том,
 * что Robokassa сама провалидирует подпись, когда пользователь перейдёт
 * по ссылке.
 *
 * @param float  $amount      Сумма к оплате в рублях
 * @param string $description Описание платежа (видно клиенту и в кабинете Robokassa)
 * @param int    $invId       Номер счёта (см. nextRobokassaInvId())
 * @return array{id:string,url:string}|null
 */
function createRobokassaPayment($amount, $description, $invId) {
    if (!robokassaConfigured() || !$invId) {
        return null;
    }

    $outSum = formatRobokassaOutSum($amount);
    $signature = md5(ROBOKASSA_MERCHANT_LOGIN . ':' . $outSum . ':' . $invId . ':' . ROBOKASSA_PASSWORD1);

    $params = [
        'MerchantLogin'  => ROBOKASSA_MERCHANT_LOGIN,
        'OutSum'         => $outSum,
        'InvId'          => $invId,
        'Description'    => mb_substr($description, 0, 100),
        'SignatureValue' => $signature,
        'Culture'        => 'ru',
        'Encoding'       => 'utf-8',
    ];
    // IsTest передаём, только когда явно включён тестовый режим —
    // при ROBOKASSA_IS_TEST = false параметр не отправляем совсем
    // (значение "0" Robokassa трактует так же, как и отсутствие
    // параметра, но лучше не полагаться на это неявно).
    if (defined('ROBOKASSA_IS_TEST') && ROBOKASSA_IS_TEST) {
        $params['IsTest'] = 1;
    }

    $url = 'https://auth.robokassa.ru/Merchant/Index.aspx?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return ['id' => (string)$invId, 'url' => $url];
}

/**
 * Проверяет подпись уведомления, пришедшего на ResultURL.
 *
 * Формула из документации Robokassa ("Формирование подписи" для
 * серверных уведомлений): MD5(OutSum:InvId:Пароль#2). Используется
 * ИМЕННО Пароль#2 (не Пароль#1, которым подписывается создание
 * платежа) — это отдельный секрет, известный только магазину и
 * Robokassa, поэтому подделать уведомление, не зная его, нельзя.
 * Сравнение регистронезависимое (Robokassa может прислать подпись
 * в любом регистре) и через hash_equals — чтобы не давать наводок
 * по времени сравнения.
 */
function robokassaVerifyResultSignature($outSum, $invId, $signatureValue): bool {
    if (!defined('ROBOKASSA_PASSWORD2') || ROBOKASSA_PASSWORD2 === '') {
        return false;
    }
    $expected = md5($outSum . ':' . $invId . ':' . ROBOKASSA_PASSWORD2);
    return hash_equals(strtolower($expected), strtolower((string)$signatureValue));
}

/**
 * Проверяет подпись, с которой Robokassa возвращает клиента на сайт
 * через SuccessURL/FailURL (метод GET). В отличие от ResultURL здесь
 * используется Пароль#1 — та же пара, которой подписывается создание
 * платежа: MD5(OutSum:InvId:Пароль#1).
 *
 * Используется вместо собственного маркера ?payment=return в самом
 * URL — так Success/Fail URL в кабинете Robokassa можно оставить
 * "чистыми" (без query-параметров), что требуется при методе GET
 * (см. валидацию в Технических настройках магазина), а факт возврата
 * именно со страницы оплаты Robokassa всё равно надёжно определяется
 * по параметрам, которые Robokassa сама подставляет в GET-запрос.
 *
 * ВАЖНО: это только признак "человек вернулся со страницы Robokassa",
 * а не подтверждение оплаты — окончательный статус выставляется
 * только через result.php (ResultURL), т.к. переход по SuccessURL
 * происходит в браузере клиента и теоретически может не дойти до
 * сервера (закрыл вкладку и т.п.), в отличие от серверного ResultURL.
 */
function robokassaVerifyReturnSignature($outSum, $invId, $signatureValue): bool {
    if (!robokassaConfigured()) {
        return false;
    }
    $expected = md5($outSum . ':' . $invId . ':' . ROBOKASSA_PASSWORD1);
    return hash_equals(strtolower($expected), strtolower((string)$signatureValue));
}

/**
 * Пишет диагностику по входящим уведомлениям ResultURL в
 * private/logs/robokassa-result.log — отдельно от zayavki-*.log,
 * чтобы не путать заявки клиентов с технической отладкой.
 *
 * Логируем КАЖДОЕ обращение к result.php (и успешное, и с ошибкой) —
 * пока идёт отладка интеграции, это единственный способ увидеть,
 * доходят ли вообще уведомления от Robokassa до сервера и что именно
 * в них приходит, не имея доступа к истории уведомлений в кабинете
 * Robokassa.
 */
function logRobokassaResult(string $status, array $context): void {
    if (!defined('LOGS_PATH')) {
        return;
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $status . ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents(LOGS_PATH . '/robokassa-result.log', $line, FILE_APPEND | LOCK_EX);
}
