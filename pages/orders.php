<?php
function orderTotal(array $o): int {
  return (int)$o['m2'] * (int)$o['days'] * (int)$o['price_per_m2'];
}

function orderDebt(array $o): int {
  return max(0, orderTotal($o) - (int)$o['deposit'] - (int)$o['paid_amount']);
}

function syncPaymentStatus(PDO $db, int $order_id, array $o): void {
  $debt = orderDebt($o);
  $status = 'Не оплачено';
  if ($debt <= 0) {
    $status = 'Оплачено';
  } elseif ((int)$o['deposit'] > 0 || (int)$o['paid_amount'] > 0) {
    $status = 'Частично';
  }
  $db->prepare('UPDATE orders SET payment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
     ->execute([':status' => $status, ':id' => $order_id]);
}

// handle return action
if (!empty($_GET['return'])) {
  $oid = (int)$_GET['return'];
  $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
  $stmt->execute([':id'=>$oid]);
  $o = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($o && $o['status'] !== 'Возвращено') {
    $remaining = max(0, (int)$o['m2'] - (int)$o['returned_m2']);
    if ($remaining > 0) {
      $newReturned = (int)$o['returned_m2'] + $remaining;
      $db->prepare("UPDATE orders SET returned_m2 = :returned, status='Возвращено', updated_at = CURRENT_TIMESTAMP WHERE id = :id")
         ->execute([':returned'=>$newReturned, ':id'=>$oid]);
      $db->prepare("INSERT INTO inventory_movements (inventory_type, delta_m2, reason, related_order_id) VALUES (:type, :delta, 'возврат', :oid)")
         ->execute([':type'=>$o['inventory_type'],':delta'=>$remaining,':oid'=>$oid]);
      $db->prepare("UPDATE inventory SET total_m2 = total_m2 + :m2 WHERE type = :type")
         ->execute([':m2'=>$remaining,':type'=>$o['inventory_type']]);
    }
    header('Location: /?page=orders'); exit;
  }
}

// filters
$where = [];
$params = [];
if (!empty($_GET['inventory_type'])) { $where[] = 'inventory_type = :it'; $params[':it'] = $_GET['inventory_type']; }
if (!empty($_GET['status'])) { $where[] = 'status = :st'; $params[':st'] = $_GET['status']; }
if (!empty($_GET['client'])) { $where[] = 'client_name LIKE :cn'; $params[':cn'] = '%'.$_GET['client'].'%'; }
if (!empty($_GET['phone'])) { $where[] = 'client_phone LIKE :phone'; $params[':phone'] = '%'.$_GET['phone'].'%'; }
if (!empty($_GET['client_id'])) { $where[] = 'client_id = :client_id'; $params[':client_id'] = (int)$_GET['client_id']; }
$sql = 'SELECT * FROM orders' . (count($where)? ' WHERE '.implode(' AND ',$where): '') . ' ORDER BY created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSV export
if (!empty($_GET['export']) && $_GET['export']==='csv'){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=orders.csv');
  $out = fopen('php://output','w');
  fputcsv($out, ['id','client','phone','inventory','m2','returned_m2','days','price','sum','deposit','paid','debt','payment_status','date_start','date_end','status']);
  foreach($orders as $o){
    fputcsv($out, [$o['id'],$o['client_name'],$o['client_phone'],$o['inventory_type'],$o['m2'],$o['returned_m2'],$o['days'],$o['price_per_m2'],orderTotal($o),$o['deposit'],$o['paid_amount'],orderDebt($o),$o['payment_status'],$o['date_start'],$o['date_end'],$o['status']]);
  }
  exit;
}
?>
<div class="card">
  <div class="page-header">
    <h1>Заказы</h1>
    <div class="actions">
      <a class="badge" href="/?page=order_create">Новая аренда</a>
      <a class="badge" href="/?page=orders&export=csv">Экспорт CSV</a>
    </div>
  </div>
  <form method="get" class="filter-form">
  <input type="hidden" name="page" value="orders">
  <label>Клиент <input name="client" value="<?php echo htmlspecialchars($_GET['client'] ?? ''); ?>"></label>
  <label>Телефон <input name="phone" inputmode="tel" value="<?php echo htmlspecialchars($_GET['phone'] ?? ''); ?>"></label>
  <label>Опалубка <input name="inventory_type" value="<?php echo htmlspecialchars($_GET['inventory_type'] ?? ''); ?>"></label>
  <label>Статус <input name="status" value="<?php echo htmlspecialchars($_GET['status'] ?? ''); ?>"></label>
  <button>Фильтр</button>
</form>
 
  <div style="overflow:auto">
  <table>
  <thead>
    <tr>
      <th>ID</th><th>Клиент</th><th>Телефон</th><th>Опалубка</th><th>м²</th><th>Долг</th><th>Оплата</th><th>Возврат</th><th>Статус</th><th>Действия</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($orders as $o): ?>
    <?php
      $total = orderTotal($o);
      $debt = orderDebt($o);
      $remaining = max(0, (int)$o['m2'] - (int)$o['returned_m2']);
      $isOverdue = $o['status'] !== 'Возвращено' && $o['date_end'] < date('Y-m-d');
    ?>
    <tr>
      <td data-label="ID"><?php echo $o['id']; ?></td>
      <td data-label="Клиент"><a href="/?page=orders&client_id=<?php echo $o['client_id']; ?>"><?php echo htmlspecialchars($o['client_name']); ?></a></td>
      <td data-label="Телефон"><?php echo htmlspecialchars($o['client_phone']); ?></td>
      <td data-label="Опалубка"><?php echo htmlspecialchars($o['inventory_type']); ?></td>
      <td data-label="м²"><?php echo $o['m2']; ?> / осталось <?php echo $remaining; ?></td>
      <td data-label="Долг"><?php echo number_format($debt,0,'',' '); ?> ₸<br><span class="muted">из <?php echo number_format($total,0,'',' '); ?> ₸</span></td>
      <td data-label="Оплата"><span class="status-pill"><?php echo htmlspecialchars($o['payment_status']); ?></span></td>
      <td data-label="Возврат"><?php echo $o['date_end']; ?><?php if($isOverdue): ?><br><span class="danger-text">Просрочено</span><?php endif; ?></td>
      <td data-label="Статус"><?php echo $o['status']; ?></td>
      <td data-label="Действия">
        <div class="row-actions">
          <a class="mini-link" href="/?page=order_view&id=<?php echo $o['id']; ?>">Открыть</a>
          <a class="mini-link" href="/?page=order_act&id=<?php echo $o['id']; ?>">Акт/PDF</a>
          <?php if($remaining > 0): ?>
            <a class="mini-link" href="/?page=orders&return=<?php echo $o['id']; ?>" onclick="return confirm('Подтвердить полный возврат?')">Полный возврат</a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
  </div>
</div>
