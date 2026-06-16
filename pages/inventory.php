<?php
// inventory list and movements
$inventory_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'create';
  $type = trim($_POST['type'] ?? '');
  $total_m2 = (int)($_POST['total_m2'] ?? 0);

  if ($type === '') {
    $inventory_error = 'Укажите название товара';
  } elseif ($total_m2 < 0 || ($action === 'create' && $total_m2 <= 0)) {
    $inventory_error = 'Количество должно быть больше 0';
  } elseif ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM inventory WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) {
      $inventory_error = 'Товар не найден';
    } else {
      $delta = $total_m2 - (int)$old['total_m2'];
      $stmt = $db->prepare('UPDATE inventory SET type = :type, total_m2 = :m2 WHERE id = :id');
      $stmt->execute([':type' => $type, ':m2' => $total_m2, ':id' => $id]);
      $db->prepare('UPDATE orders SET inventory_type = :new_type WHERE inventory_type = :old_type')
         ->execute([':new_type' => $type, ':old_type' => $old['type']]);
      if ($delta !== 0) {
        $stmt = $db->prepare("INSERT INTO inventory_movements (inventory_type, delta_m2, reason) VALUES (:type, :delta, 'корректировка')");
        $stmt->execute([':type' => $type, ':delta' => $delta]);
      }
      header('Location: /?page=inventory&updated=1');
      exit;
    }
  } else {
    $stmt = $db->prepare('SELECT id FROM inventory WHERE lower(type) = lower(:type) LIMIT 1');
    $stmt->execute([':type' => $type]);
    $existing_id = $stmt->fetchColumn();

    if ($existing_id) {
      $stmt = $db->prepare('UPDATE inventory SET total_m2 = total_m2 + :m2 WHERE id = :id');
      $stmt->execute([':m2' => $total_m2, ':id' => $existing_id]);
      $reason = 'пополнение';
    } else {
      $stmt = $db->prepare('INSERT INTO inventory (type, total_m2) VALUES (:type, :m2)');
      $stmt->execute([':type' => $type, ':m2' => $total_m2]);
      $reason = 'новый товар';
    }

    $stmt = $db->prepare("INSERT INTO inventory_movements (inventory_type, delta_m2, reason) VALUES (:type, :delta, :reason)");
    $stmt->execute([':type' => $type, ':delta' => $total_m2, ':reason' => $reason]);
    header('Location: /?page=inventory&created=1');
    exit;
  }
}

$items = $db->query('SELECT * FROM inventory ORDER BY type')->fetchAll(PDO::FETCH_ASSOC);
$movements = $db->query('SELECT * FROM inventory_movements ORDER BY created_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <div class="page-header"><h1>Добавить товар</h1></div>
  <?php if(!empty($_GET['created'])): ?>
    <div class="notice">Склад обновлен</div>
  <?php endif; ?>
  <?php if(!empty($_GET['updated'])): ?>
    <div class="notice">Товар обновлен</div>
  <?php endif; ?>
  <?php if($inventory_error): ?>
    <div class="error"><?php echo htmlspecialchars($inventory_error); ?></div>
  <?php endif; ?>
  <form method="post" class="form-grid compact-form">
    <input type="hidden" name="action" value="create">
    <label>Название товара
      <input name="type" value="<?php echo htmlspecialchars($_POST['type'] ?? ''); ?>">
    </label>
    <label>Количество м²
      <input name="total_m2" type="number" inputmode="numeric" min="1" value="<?php echo htmlspecialchars($_POST['total_m2'] ?? ''); ?>">
    </label>
    <div class="full"><button>Добавить на склад</button></div>
  </form>
</div>

<div class="card">
  <div class="page-header"><h1>Склад</h1></div>
  <table>
    <thead><tr><th>Опалубка</th><th>Доступно м²</th><th>Правка</th></tr></thead>
    <tbody>
      <?php foreach($items as $it): ?>
      <tr>
        <td data-label="Опалубка"><?php echo htmlspecialchars($it['type']); ?></td>
        <td data-label="Доступно"><?php echo $it['total_m2']; ?></td>
        <td data-label="Правка">
          <form method="post" class="inline-edit">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
            <input name="type" value="<?php echo htmlspecialchars($it['type']); ?>">
            <input name="total_m2" type="number" inputmode="numeric" min="0" value="<?php echo $it['total_m2']; ?>">
            <button>Сохранить</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Движения</h2>
  <table>
    <thead><tr><th>Когда</th><th>Опалубка</th><th>Δ м²</th><th>Причина</th></tr></thead>
    <tbody>
    <?php foreach($movements as $m): ?>
      <tr><td data-label="Когда"><?php echo $m['created_at']; ?></td><td data-label="Опалубка"><?php echo htmlspecialchars($m['inventory_type']); ?></td><td data-label="Δ м²"><?php echo $m['delta_m2']; ?></td><td data-label="Причина"><?php echo $m['reason']; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
