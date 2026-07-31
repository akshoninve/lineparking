<?php
/**
 * bootstrap.php
 * Общая точка входа для index.php и oferta.php: подключает конфиг
 * (лежащий в /home/srv250266/private/, вне public_html) и общие
 * include-файлы проекта.
 *
 * Использование в начале index.php / oferta.php:
 *   require_once __DIR__ . '/bootstrap.php';
 */

// dirname(__DIR__) от public_html/bootstrap.php даёт /home/srv250266,
// далее заходим в private/ — туда, где лежит config.php и logs/.
require_once dirname(__DIR__) . '/private/config.php';

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/parkings-data.php';
require_once __DIR__ . '/includes/robokassa.php';
