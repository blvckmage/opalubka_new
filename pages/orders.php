<?php
function orderTotal(array $o, array $items): int {
  $rent = 0;
  $days = (int)$o['days'];
  foreach($items as $item) {
      $rent += (int)$item['m2'] * $days * (int)$item['price_per_m2'];
  }
  // legacy fallback if no items
  if (empty($items)) {
      $rent = (int)$o['m2'] * $days * (int)$o['price_per_m2'];
  }
  
  $tax = (int)round($rent * ((int)($o['tax_percentage'] ?? 0)) / 100);
  $delivery = (int)($o['delivery_fee'] ?? 0);
  $discount = (int)round($rent * ((int)($o['discount_percentage'] ?? 0)) / 100);
  return max(0, $rent + $tax - $discount);
}

function orderDebt(array $o, array $items): int {
  return max(0, orderTotal($o, $items) - (int)$o['deposit'] - (int)$o['paid_amount']);
}

// handle return action
if (!empty($_GET['return'])) {
  $oid = (int)$_GET['return'];
  $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
  $stmt->execute([':id'=>$oid]);
  $o = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($o && $o['status'] !== 'Возвращено') {
    $items = $db->query("SELECT * FROM order_items WHERE order_id = $oid")->fetchAll(PDO::FETCH_ASSOC);
    
    // Process items
    foreach($items as $item) {
       $remaining = max(0, (int)$item['m2'] - (int)$item['returned_m2']);
       if ($remaining > 0) {
           $newReturned = (int)$item['returned_m2'] + $remaining;
           $db->prepare("UPDATE order_items SET returned_m2 = :ret WHERE id = :id")->execute([':ret'=>$newReturned, ':id'=>$item['id']]);
           $db->prepare("INSERT INTO inventory_movements (inventory_type, delta_m2, reason, related_order_id) VALUES (:type, :delta, 'возврат', :oid)")
             ->execute([':type'=>$item['inventory_type'],':delta'=>$remaining,':oid'=>$oid]);
           $db->prepare("UPDATE inventory SET total_m2 = total_m2 + :m2 WHERE type = :type")
             ->execute([':m2'=>$remaining,':type'=>$item['inventory_type']]);
       }
    }
    
    // Fallback for legacy items without order_items
    if (empty($items)) {
        $remaining = max(0, (int)$o['m2'] - (int)$o['returned_m2']);
        if ($remaining > 0) {
            $newReturned = (int)$o['returned_m2'] + $remaining;
            $db->prepare("UPDATE orders SET returned_m2 = :returned WHERE id = :id")
               ->execute([':returned'=>$newReturned, ':id'=>$oid]);
            $db->prepare("INSERT INTO inventory_movements (inventory_type, delta_m2, reason, related_order_id) VALUES (:type, :delta, 'возврат', :oid)")
               ->execute([':type'=>$o['inventory_type'],':delta'=>$remaining,':oid'=>$oid]);
            $db->prepare("UPDATE inventory SET total_m2 = total_m2 + :m2 WHERE type = :type")
               ->execute([':m2'=>$remaining,':type'=>$o['inventory_type']]);
        }
    }
    
    $db->prepare("UPDATE orders SET status='Возвращено', updated_at = CURRENT_TIMESTAMP WHERE id = :id")
       ->execute([':id'=>$oid]);
    header('Location: /?page=orders'); exit;
  }
}

// filters
$where = [];
$params = [];
if (!empty($_GET['inventory_type'])) { 
    $where[] = '(inventory_type = :it OR EXISTS (SELECT 1 FROM order_items WHERE order_items.order_id = orders.id AND order_items.inventory_type = :it))'; 
    $params[':it'] = $_GET['inventory_type']; 
}
if (!empty($_GET['status'])) { $where[] = 'status = :st'; $params[':st'] = $_GET['status']; }
if (!empty($_GET['client'])) { $where[] = 'client_name LIKE :cn'; $params[':cn'] = '%'.$_GET['client'].'%'; }
if (!empty($_GET['phone'])) { $where[] = 'client_phone LIKE :phone'; $params[':phone'] = '%'.$_GET['phone'].'%'; }
if (!empty($_GET['client_id'])) { $where[] = 'client_id = :client_id'; $params[':client_id'] = (int)$_GET['client_id']; }
$sql = 'SELECT * FROM orders' . (count($where)? ' WHERE '.implode(' AND ',$where): '') . ' ORDER BY created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all order_items
$all_items_stmt = $db->query('SELECT * FROM order_items');
$all_items = $all_items_stmt->fetchAll(PDO::FETCH_ASSOC);
$items_by_order = [];
foreach($all_items as $it) {
    $items_by_order[$it['order_id']][] = $it;
}

// CSV export
if (!empty($_GET['export']) && $_GET['export']==='csv'){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=orders.csv');
  $out = fopen('php://output','w');
  fputcsv($out, ['id','client','phone','inventory','m2','returned_m2','days','sum','deposit','paid','debt','payment_status','date_start','date_end','status']);
  foreach($orders as $o){
    $my_items = $items_by_order[$o['id']] ?? [];
    $inv_strings = [];
    $total_m2 = 0;
    $total_rem = 0;
    foreach($my_items as $mi) {
        $inv_strings[] = $mi['inventory_type'];
        $total_m2 += $mi['m2'];
        $total_rem += max(0, $mi['m2'] - $mi['returned_m2']);
    }
    $inv_display = empty($inv_strings) ? $o['inventory_type'] : implode(', ', $inv_strings);
    if (empty($inv_display)) $inv_display = '—';
    if (empty($my_items)) {
        $total_m2 = $o['m2'];
        $total_rem = max(0, (int)$o['m2'] - (int)$o['returned_m2']);
    }

    fputcsv($out, [$o['id'],$o['client_name'],$o['client_phone'],$inv_display,$total_m2,$total_m2 - $total_rem,$o['days'],orderTotal($o, $my_items),$o['deposit'],$o['paid_amount'],orderDebt($o, $my_items),$o['payment_status'],$o['date_start'],$o['date_end'],$o['status']]);
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
      <th>ID</th><th>Клиент</th><th>Телефон</th><th>Опалубка</th><th>Кол-во</th><th>Сумма</th><th>Срок возврата</th><th>Статус</th><th>Действия</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($orders as $o): ?>
    <?php
      $my_items = $items_by_order[$o['id']] ?? [];
      $inv_strings = [];
      $total_m2 = 0;
      $total_rem = 0;
      foreach($my_items as $mi) {
          $inv_strings[] = $mi['inventory_type'];
          $total_m2 += $mi['m2'];
          $total_rem += max(0, $mi['m2'] - $mi['returned_m2']);
      }
      $inv_display = empty($inv_strings) ? $o['inventory_type'] : implode(', ', $inv_strings);
      if (empty($inv_display)) $inv_display = '—';
      if (empty($my_items)) {
          $total_m2 = $o['m2'];
          $total_rem = max(0, (int)$o['m2'] - (int)$o['returned_m2']);
      }

      $total = orderTotal($o, $my_items);
      $debt = orderDebt($o, $my_items);
      $isOverdue = $o['status'] !== 'Возвращено' && $o['date_end'] < date('Y-m-d');
    ?>
    <tr>
      <td data-label="ID"><?php echo $o['id']; ?></td>
      <td data-label="Клиент"><a href="/?page=orders&client_id=<?php echo $o['client_id']; ?>"><?php echo htmlspecialchars($o['client_name']); ?></a></td>
      <td data-label="Телефон"><?php echo htmlspecialchars($o['client_phone']); ?></td>
      <td data-label="Опалубка"><?php echo htmlspecialchars($inv_display); ?></td>
      <td data-label="Кол-во"><?php echo $total_m2; ?> / осталось <?php echo $total_rem; ?></td>
      <td data-label="Сумма"><?php echo number_format($total,0,'',' '); ?> ₸</td>
      <td data-label="Срок возврата"><?php echo $o['date_end']; ?><?php if($isOverdue): ?><br><span class="danger-text">Просрочено</span><?php endif; ?></td>
      <td data-label="Статус"><?php echo $o['status']; ?></td>
      <td data-label="Действия">
        <div class="row-actions">
          <a class="mini-link" href="/?page=order_view&id=<?php echo $o['id']; ?>">Открыть</a>
          <a class="mini-link" href="/?page=order_act&id=<?php echo $o['id']; ?>">Акт/PDF</a>
          <?php if($total_rem > 0): ?>
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
