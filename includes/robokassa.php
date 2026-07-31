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
 *      ResultURL: https://xn--80aaaxdesfic0ah2j.xn--p1ai/result.php
 *      SuccessURL: https://xn--80aaaxdesfic0ah2j.xn--p1ai/index.php?payment=return
 *      FailURL:    https://xn--80aaaxdesfic0ah2j.xn--p1ai/index.php?payment=return
 *    (punycode того же кириллического домена, что и раньше у ЮKassa).
 * 2. Скопируйте MerchantLogin, Пароль №1 и Пароль №2 (для тестового
 *    режима — ОТДЕЛЬНУЮ тестовую пару паролей из того же раздела
 *    Технических настроек, не боевую).
 * 3. Впишите их в /home/srv250266/config.php:
 *        define('ROBOKASSA_MERCHANT_LOGIN', 'ваш_логин');
 *        define('ROBOKASSA_PASSWORD1', 'тестовый_или_боевой_пароль_1');
 *        define('ROBOKASSA_PASSWORD2', 'тестовый_или_боевой_пароль_2');
 *        define('ROBOKASSA_IS_TEST', true); // true — тестовый режим, false — боевой
 * 4. Больше ничего менять не нужно — форма на сайте сама начнёт
 *    переводить клиента на страницу оплаты Robokassa. Пока
 *    ROBOKASSA_IS_TEST = true, деньги не списываются по-настоящему
 *    (см. документацию Robokassa, раздел "Тестовый режим") — используются
 *    именно тестовые пароли, не боевые.
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
