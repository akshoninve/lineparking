<?php
/**
 * admin/login.php
 * Вход в панель администратора (логин/пароль из private/config.php —
 * см. комментарий в includes/admin-auth.php про ADMIN_LOGIN / ADMIN_PASSWORD_HASH).
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/admin-auth.php';

if (isAdminLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    if (attemptAdminLogin($password)) {
        header('Location: index.php');
        exit;
    }
    $error = 'Неверный пароль.';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход — Панель администратора ЛайнПаркинг</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-login-body">
<div class="login-box">
  <div class="brand"><span class="p-sign">P</span>ЛайнПаркинг · Панель администратора</div>
  <?php if ($error): ?><div class="login-err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post" novalidate>
    <div class="field">
      <label for="password">Пароль</label>
      <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
    </div>
    <button type="submit" class="btn">Войти</button>
  </form>
</div>
</body>
</html>
