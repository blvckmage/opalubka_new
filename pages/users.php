<?php
if (($_SESSION['role'] ?? '') !== 'admin') {
    echo '<div class="card"><div class="error">Доступ запрещен. Только администратор может управлять сотрудниками.</div></div>';
    return;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'manager';
        
        if (!$username || !$password) {
            $error = 'Введите имя пользователя и пароль';
        } else {
            // Check if user exists
            $stmt = $db->prepare('SELECT id FROM users WHERE username = :u');
            $stmt->execute([':u' => $username]);
            if ($stmt->fetch()) {
                $error = 'Пользователь с таким именем уже существует';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (:u, :p, :r)");
                $stmt->execute([':u' => $username, ':p' => $hash, ':r' => $role]);
                $success = 'Пользователь успешно создан';
            }
        }
    }
    
    if ($action === 'delete') {
        $delete_id = (int)($_POST['id'] ?? 0);
        
        // Prevent deleting oneself
        $stmt = $db->prepare('SELECT username FROM users WHERE id = :id');
        $stmt->execute([':id' => $delete_id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($u && $u['username'] === $_SESSION['user']) {
            $error = 'Вы не можете удалить свой собственный аккаунт';
        } else {
            $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => $delete_id]);
            $success = 'Пользователь удален';
        }
    }
}

$users = $db->query("SELECT id, username, role FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card">
  <div class="page-header">
    <h1>Управление сотрудниками</h1>
  </div>
  
  <?php if($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if($success): ?><div class="notice"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <form method="post" class="form-grid compact-form">
    <input type="hidden" name="action" value="create">
    <label>Имя пользователя (логин)
      <input name="username" required>
    </label>
    <label>Пароль
      <input type="password" name="password" required>
    </label>
    <label>Роль
      <select name="role">
        <option value="manager">Менеджер</option>
        <option value="admin">Администратор</option>
      </select>
    </label>
    <div class="full"><button>Добавить сотрудника</button></div>
  </form>
</div>

<div class="card">
  <div class="page-header"><h2>Список сотрудников</h2></div>
  <div style="overflow:auto">
    <table>
      <thead>
        <tr><th>ID</th><th>Логин</th><th>Роль</th><th>Действия</th></tr>
      </thead>
      <tbody>
        <?php foreach($users as $u): ?>
          <tr>
            <td data-label="ID"><?php echo $u['id']; ?></td>
            <td data-label="Логин"><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
            <td data-label="Роль"><?php echo $u['role'] === 'admin' ? 'Администратор' : 'Менеджер'; ?></td>
            <td data-label="Действия">
              <?php if($u['username'] !== $_SESSION['user']): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Точно удалить пользователя?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                  <button type="submit" class="danger-text" style="background:none;border:none;padding:0;cursor:pointer;text-decoration:underline;">Удалить</button>
                </form>
              <?php else: ?>
                <span class="muted">Это вы</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
