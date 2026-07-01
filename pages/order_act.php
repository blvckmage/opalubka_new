<?php
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
$stmt->execute([':id' => $id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
  echo '<div class="card"><div class="error">Заказ не найден</div></div>';
  return;
}

$stmt = $db->prepare('SELECT * FROM order_items WHERE order_id = :id');
$stmt->execute([':id' => $id]);
$order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rent = 0;
foreach($order_items as $item) {
    $rent += (int)$item['m2'] * (int)$order['days'] * (int)$item['price_per_m2'];
}
if (empty($order_items)) {
    $rent = (int)$order['m2'] * (int)$order['days'] * (int)$order['price_per_m2'];
}

$tax = (int)round($rent * ((int)($order['tax_percentage'] ?? 0)) / 100);
$delivery = (int)($order['delivery_fee'] ?? 0);
$discount = (int)round($rent * ((int)($order['discount_percentage'] ?? 0)) / 100);
$total = max(0, $rent + $tax - $discount);

$debt = max(0, $total - (int)$order['deposit'] - (int)$order['paid_amount']);
?>
<div class="print-actions">
  <a class="badge" href="/?page=order_view&id=<?php echo $order['id']; ?>">Назад к заказу</a>
  <button type="button" onclick="window.print()">Сохранить PDF / Печать</button>
</div>

<section class="act-sheet">
  <header class="act-header">
    <div>
      <h1>Акт аренды опалубки №<?php echo $order['id']; ?></h1>
      <p>Дата формирования: <?php echo date('Y-m-d'); ?></p>
    </div>
    <strong>Опалубка CRM</strong>
  </header>

  <div class="act-grid">
    <div>
      <h2>Клиент</h2>
      <p><?php echo htmlspecialchars($order['client_name']); ?></p>
      <p><?php echo htmlspecialchars($order['client_phone']); ?></p>
      <p><?php echo htmlspecialchars($order['address']); ?></p>
    </div>
    <div>
      <h2>Срок аренды</h2>
      <p><?php echo $order['date_start']; ?> - <?php echo $order['date_end']; ?></p>
      <p><?php echo (int)$order['days']; ?> дней</p>
    </div>
  </div>

  <table>
    <thead>
      <tr><th>Товар</th><th>Выдано</th><th>Возвращено</th><th>Осталось</th><th>Цена/день</th><th>Сумма за дни</th></tr>
    </thead>
    <tbody>
      <?php if(!empty($order_items)): foreach($order_items as $it): 
        $rem = $it['m2'] - $it['returned_m2'];
        $sum = $it['m2'] * (int)$order['days'] * $it['price_per_m2'];
      ?>
      <tr>
        <td data-label="Товар"><?php echo htmlspecialchars($it['inventory_type']); ?></td>
        <td data-label="Выдано"><?php echo (int)$it['m2']; ?> ед.</td>
        <td data-label="Возвращено"><?php echo (int)$it['returned_m2']; ?> ед.</td>
        <td data-label="Осталось"><?php echo $rem; ?> ед.</td>
        <td data-label="Цена/день"><?php echo number_format($it['price_per_m2'],0,'',' '); ?> ₸</td>
        <td data-label="Сумма"><?php echo number_format($sum,0,'',' '); ?> ₸</td>
      </tr>
      <?php endforeach; else: 
        $rem = $order['m2'] - $order['returned_m2'];
      ?>
      <tr>
        <td data-label="Товар"><?php echo htmlspecialchars($order['inventory_type']); ?></td>
        <td data-label="Выдано"><?php echo (int)$order['m2']; ?> ед.</td>
        <td data-label="Возвращено"><?php echo (int)$order['returned_m2']; ?> ед.</td>
        <td data-label="Осталось"><?php echo $rem; ?> ед.</td>
        <td data-label="Цена/день"><?php echo number_format($order['price_per_m2'],0,'',' '); ?> ₸</td>
        <td data-label="Сумма"><?php echo number_format($rent,0,'',' '); ?> ₸</td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="act-summary">
    <p><strong>Сумма аренды товаров:</strong> <?php echo number_format($rent,0,'',' '); ?> ₸</p>
    <?php if($tax > 0): ?><p><strong>Налог:</strong> <?php echo number_format($tax,0,'',' '); ?> ₸</p><?php endif; ?>
    <?php if($discount > 0): ?><p><strong>Скидка:</strong> -<?php echo number_format($discount,0,'',' '); ?> ₸</p><?php endif; ?>
    <p style="font-size: 1.2em; color: var(--text);"><strong>Итого к оплате:</strong> <?php echo number_format($total,0,'',' '); ?> ₸</p>
  </div>

  <?php if(!empty($order['comment'])): ?>
    <div class="act-comment" style="margin-top: 15px;">
      <h2>Комментарий</h2>
      <p><?php echo nl2br(htmlspecialchars($order['comment'])); ?></p>
    </div>
  <?php endif; ?>

  <div class="signatures">
    <div><span></span><p>Передал</p></div>
    <div><span></span><p>Принял</p></div>
  </div>
</section>
