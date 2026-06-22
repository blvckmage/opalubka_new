<?php
ob_start(); // Buffer output so headers can be sent anywhere
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/header.php';

$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard','orders','order_create','order_view','order_act','inventory','clients','expenses','login','logout', 'users'];
if (!in_array($page, $allowed)) $page = 'dashboard';

// allow access to login/logout without auth
if (in_array($page, ['login','logout'])) {
	include __DIR__ . '/pages/' . $page . '.php';
} else {
	if (empty($_SESSION['user'])) {
		header('Location: /?page=login');
		exit;
	}
	include __DIR__ . '/pages/' . $page . '.php';
}

require_once __DIR__ . '/inc/footer.php';
?>
