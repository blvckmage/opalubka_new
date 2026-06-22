<?php
if (session_status() === PHP_SESSION_NONE) session_start();
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#14532d">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Опалубка CRM">
  <title>Опалубка CRM</title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="app-shell">
<?php
$currentPage = $_GET['page'] ?? 'dashboard';
$navItems = [
  'dashboard' => ['Панель', '⌂'],
  'orders' => ['Заказы', '≡'],
  'order_create' => ['Аренда', '+'],
  'inventory' => ['Склад', '▦'],
  'clients' => ['Клиенты', '◦'],
  'expenses' => ['Расходы', '₸'],
];
?>
<nav class="topbar">
  <a class="brand" href="/?page=dashboard" aria-label="Опалубка CRM">
    <span class="brand-mark">О</span>
    <span>
      <strong>Опалубка CRM</strong>
      <small>учет аренды</small>
    </span>
  </a>
  <button class="nav-toggle" id="navToggle" type="button" aria-expanded="false" aria-controls="navLinks">☰</button>
  <div class="navlinks" id="navLinks">
    <?php foreach($navItems as $pageKey => $item): ?>
      <a class="<?php echo $currentPage === $pageKey ? 'active' : ''; ?>" href="/?page=<?php echo $pageKey; ?>"><?php echo htmlspecialchars($item[0]); ?></a>
    <?php endforeach; ?>
    <?php if(!empty($_SESSION['user'])): ?>
      <?php if(($_SESSION['role'] ?? '') === 'admin'): ?>
        <a class="<?php echo $currentPage === 'users' ? 'active' : ''; ?>" href="/?page=users">Сотрудники</a>
      <?php endif; ?>
      <a class="logout-link" href="/?page=logout">Выйти</a>
    <?php else: ?>
      <a href="/?page=login">Войти</a>
    <?php endif; ?>
  </div>
</nav>
<main class="container">
