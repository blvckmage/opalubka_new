<?php
// inventory list and movements
$inventory_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'update';
  $type = trim($_POST['type'] ?? '');
  $total_m2 = (int)($_POST['total_m2'] ?? 0);
  $price = (int)($_POST['price'] ?? 0);
  $unit = trim($_POST['unit'] ?? 'ед.');

  if ($type === '') {
    $inventory_error = 'Укажите название товара';
  } elseif ($total_m2 < 0) {
    $inventory_error = 'Количество должно быть >= 0';
  } elseif ($price < 0) {
    $inventory_error = 'Цена должна быть >= 0';
  } elseif ($unit === '') {
    $inventory_error = 'Укажите единицу измерения';
  } elseif ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM inventory WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) {
      $inventory_error = 'Товар не найден';
    } else {
      $delta = $total_m2 - (int)$old['total_m2'];
      $stmt = $db->prepare('UPDATE inventory SET type = :type, total_m2 = :m2, price = :price, unit = :unit WHERE id = :id');
      $stmt->execute([':type' => $type, ':m2' => $total_m2, ':price' => $price, ':unit' => $unit, ':id' => $id]);
      $db->prepare('UPDATE orders SET inventory_type = :new_type WHERE inventory_type = :old_type')
         ->execute([':new_type' => $type, ':old_type' => $old['type']]);
      if ($delta !== 0) {
        $stmt = $db->prepare("INSERT INTO inventory_movements (inventory_type, delta_m2, reason) VALUES (:type, :delta, 'корректировка')");
        $stmt->execute([':type' => $type, ':delta' => $delta]);
      }
      header('Location: /?page=inventory&updated=1');
      exit;
    }
  }
}

$items = $db->query('SELECT * FROM inventory ORDER BY type')->fetchAll(PDO::FETCH_ASSOC);
$movements = $db->query('SELECT * FROM inventory_movements ORDER BY created_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <?php if(!empty($_GET['updated'])): ?>
    <div class="notice">Товар обновлен</div>
  <?php endif; ?>
  <?php if($inventory_error): ?>
    <div class="error"><?php echo htmlspecialchars($inventory_error); ?></div>
  <?php endif; ?>
  <div class="page-header"><h1>Склад</h1></div>
  <table>
    <thead><tr><th>Опалубка</th><th>Доступно</th><th>Цена (₸)</th><th>Ед.</th></tr></thead>
    <tbody>
      <?php foreach($items as $it): ?>
      <tr class="clickable-row" data-id="<?php echo $it['id']; ?>" data-type="<?php echo htmlspecialchars($it['type']); ?>" data-m2="<?php echo $it['total_m2']; ?>" data-price="<?php echo $it['price']; ?>" data-unit="<?php echo htmlspecialchars($it['unit']); ?>">
        <td data-label="Опалубка"><?php echo htmlspecialchars($it['type']); ?></td>
        <td data-label="Доступно"><?php echo $it['total_m2']; ?></td>
        <td data-label="Цена (₸)"><?php echo $it['price']; ?></td>
        <td data-label="Ед."><?php echo htmlspecialchars($it['unit']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Движения</h2>
  <table>
    <thead><tr><th>Когда</th><th>Опалубка</th><th>Δ (кол-во)</th><th>Причина</th></tr></thead>
    <tbody>
    <?php foreach($movements as $m): ?>
      <tr><td data-label="Когда"><?php echo $m['created_at']; ?></td><td data-label="Опалубка"><?php echo htmlspecialchars($m['inventory_type']); ?></td><td data-label="Δ"><?php echo $m['delta_m2']; ?></td><td data-label="Причина"><?php echo $m['reason']; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">Редактировать товар</div>
    <form method="post" class="form-grid compact-form">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="modal_id">
      <label class="full">Опалубка
        <input name="type" id="modal_type" required>
      </label>
      <label class="full">Доступно
        <input name="total_m2" id="modal_m2" type="number" inputmode="numeric" min="0" required>
      </label>
      <label class="full">Цена (₸)
        <input name="price" id="modal_price" type="number" inputmode="numeric" min="0" required>
      </label>
      <label class="full">Единица измерения
        <input name="unit" id="modal_unit" required>
      </label>
      <div class="full" style="display: flex; gap: 1rem; margin-top: 1rem;">
        <button type="submit" style="flex: 1;">Сохранить</button>
        <button type="button" id="modal_cancel" style="flex: 1; background: var(--border); color: var(--text);">Отмена</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const modal = document.getElementById('editModal');
  const cancelBtn = document.getElementById('modal_cancel');
  
  // Open modal on row click
  document.querySelectorAll('.clickable-row').forEach(row => {
    row.addEventListener('click', function() {
      document.getElementById('modal_id').value = this.dataset.id;
      document.getElementById('modal_type').value = this.dataset.type;
      document.getElementById('modal_m2').value = this.dataset.m2;
      document.getElementById('modal_price').value = this.dataset.price;
      document.getElementById('modal_unit').value = this.dataset.unit;
      modal.classList.add('show');
    });
  });

  // Close modal
  function closeModal() {
    modal.classList.remove('show');
  }
  
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) closeModal();
  });
});
</script>
