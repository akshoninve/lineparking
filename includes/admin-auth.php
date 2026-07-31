<?php
/**
 * includes/admin-auth.php
 * Простая авторизация админки: один пароль (без логина) + PHP-сессия.
 *
 * Требует константу из private/config.php:
 *   define('ADMIN_PASSWORD', '5023534');
 * Пароль хранится в открытом виде в конфиге (который лежит вне
 * public_html и недоступен через браузер) — сознательное упрощение,
 * без password_hash().
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

/**
 * Вызывать в начале защищённых страниц админки: если не залогинен —
 * редиректит на login.php и завершает выполнение.
 */
function requireAdminLogin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Проверяет пароль (сравнение constant-time через hash_equals, чтобы
 * не давать наводок по времени ответа). При успехе — пишет флаг в сессию.
 */
function attemptAdminLogin(string $password): bool {
    if (!defined('ADMIN_PASSWORD')) {
        return false;
    }
    if (!hash_equals(ADMIN_PASSWORD, $password)) {
        return false;
    }
    // Регенерируем ID сессии при входе — защита от session fixation.
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    return true;
}

function adminLogout(): void {
    $_SESSION = [];
    session_destroy();
}
