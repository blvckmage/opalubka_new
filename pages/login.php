<?php
// simple login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :u');
    $stmt->execute([':u'=>$u]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($p, $user['password'])) {
        $_SESSION['user'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header('Location: /?page=dashboard');
        exit;
    } else {
        $error = 'Неверный логин или пароль';
    }
}
?>
<div style="max-width:420px;margin:18px auto">
    <div class="card">
        <h1 style="margin-top:0">Вход</h1>
        <?php if(!empty($error)) echo "<div class='error'>".htmlspecialchars($error)."</div>"; ?>
        <form method="post">
            <label>Логин: <input name="username"></label>
            <label>Пароль: <input name="password" type="password"></label>
            <div style="margin-top:10px"><button>Войти</button></div>
        </form>
        <p class="muted-center">Демо: <strong>admin</strong> / <strong>admin</strong></p>
    </div>
</div>
