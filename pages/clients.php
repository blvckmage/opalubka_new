<?php
$client_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'create';
  $name = trim($_POST['name'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $client_type = $_POST['client_type'] ?? 'Физ.лицо';

  if ($name === '') {
    $client_error = 'Укажите имя клиента';
  } elseif ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare('UPDATE clients SET name = :name, phone = :phone, client_type = :type WHERE id = :id');
    $stmt->execute([':name' => $name, ':phone' => $phone, ':type' => $client_type, ':id' => $id]);
    $db->prepare('UPDATE orders SET client_name = :name, client_phone = :phone WHERE client_id = :id')
       ->execute([':name' => $name, ':phone' => $phone, ':id' => $id]);
    header('Location: /?page=clients&updated=1');
    exit;
  } else {
    $stmt = $db->prepare('INSERT INTO clients (name, phone, client_type) VALUES (:name, :phone, :type)');
    $stmt->execute([':name' => $name, ':phone' => $phone, ':type' => $client_type]);
    header('Location: /?page=clients&created=1');
    exit;
  }
}

$where = [];
$params = [];
if (!empty($_GET['phone'])) {
  $where[] = 'phone LIKE :phone';
  $params[':phone'] = '%' . $_GET['phone'] . '%';
}
if (!empty($_GET['client'])) {
  $where[] = 'name LIKE :client';
  $params[':client'] = '%' . $_GET['client'] . '%';
}
$sql = 'SELECT * FROM clients' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <div class="page-header"><h1>Клиенты</h1></div>
  <?php if(!empty($_GET['created'])): ?>
    <div class="notice">Клиент добавлен</div>
  <?php endif; ?>
  <?php if(!empty($_GET['updated'])): ?>
    <div class="notice">Клиент обновлен</div>
  <?php endif; ?>
  <?php if($client_error): ?>
    <div class="error"><?php echo htmlspecialchars($client_error); ?></div>
  <?php endif; ?>
  <form method="post" class="form-grid compact-form">
    <input type="hidden" name="action" value="create">
    <label>Имя клиента
      <input name="name" autocomplete="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
    </label>
    <label>Телефон
      <input name="phone" inputmode="tel" autocomplete="tel" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
    </label>
    <label>Тип лица
      <select name="client_type">
        <option value="Физ.лицо" <?php echo (($_POST['client_type']??'')==='Физ.лицо')?'selected':''; ?>>Физ.лицо</option>
        <option value="Юр.лицо" <?php echo (($_POST['client_type']??'')==='Юр.лицо')?'selected':''; ?>>Юр.лицо</option>
      </select>
    </label>
    <div class="full"><button>Добавить клиента</button></div>
  </form>
</div>

<div class="card">
  <div class="page-header"><h1>Список клиентов</h1></div>
  <form method="get" class="filter-form">
    <input type="hidden" name="page" value="clients">
    <label>Клиент <input name="client" value="<?php echo htmlspecialchars($_GET['client'] ?? ''); ?>"></label>
    <label>Телефон <input name="phone" inputmode="tel" value="<?php echo htmlspecialchars($_GET['phone'] ?? ''); ?>"></label>
    <button>Найти</button>
  </form>
  <table>
    <thead><tr><th>ID</th><th>Имя</th><th>Телефон</th><th>Тип</th><th>История</th><th>Правка</th></tr></thead>
    <tbody>
    <?php foreach($clients as $c): ?>
      <tr>
        <td data-label="ID"><?php echo $c['id']; ?></td>
        <td data-label="Имя"><?php echo htmlspecialchars($c['name']); ?></td>
        <td data-label="Телефон"><?php echo htmlspecialchars($c['phone']); ?></td>
        <td data-label="Тип"><?php echo htmlspecialchars($c['client_type'] ?? 'Физ.лицо'); ?></td>
        <td data-label="История"><a class="mini-link" href="/?page=orders&client_id=<?php echo $c['id']; ?>">Заказы</a></td>
        <td data-label="Правка">
          <form method="post" class="inline-edit">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
            <input name="name" value="<?php echo htmlspecialchars($c['name']); ?>">
            <input name="phone" inputmode="tel" value="<?php echo htmlspecialchars($c['phone']); ?>">
            <select name="client_type">
                <option value="Физ.лицо" <?php echo (($c['client_type']??'Физ.лицо')==='Физ.лицо')?'selected':''; ?>>Физ.лицо</option>
                <option value="Юр.лицо" <?php echo (($c['client_type']??'Физ.лицо')==='Юр.лицо')?'selected':''; ?>>Юр.лицо</option>
            </select>
            <button>Сохранить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
