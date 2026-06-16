<?php
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
$stmt->execute([':id' => $id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
  echo '<div class="card"><div class="error">Заказ не найден</div></div>';
  return;
}

$total = (int)$order['m2'] * (int)$order['days'] * (int)$order['price_per_m2'];
$debt = max(0, $total - (int)$order['deposit'] - (int)$order['paid_amount']);
$remaining = max(0, (int)$order['m2'] - (int)$order['returned_m2']);
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
      <tr><th>Товар</th><th>Выдано</th><th>Возвращено</th><th>Осталось</th><th>Цена/день</th><th>Сумма</th></tr>
    </thead>
    <tbody>
      <tr>
        <td data-label="Товар"><?php echo htmlspecialchars($order['inventory_type']); ?></td>
        <td data-label="Выдано"><?php echo (int)$order['m2']; ?> м²</td>
        <td data-label="Возвращено"><?php echo (int)$order['returned_m2']; ?> м²</td>
        <td data-label="Осталось"><?php echo $remaining; ?> м²</td>
        <td data-label="Цена/день"><?php echo number_format($order['price_per_m2'],0,'',' '); ?> ₸</td>
        <td data-label="Сумма"><?php echo number_format($total,0,'',' '); ?> ₸</td>
      </tr>
    </tbody>
  </table>

  <div class="act-summary">
    <p><strong>Залог:</strong> <?php echo number_format($order['deposit'],0,'',' '); ?> ₸</p>
    <p><strong>Оплачено сверх залога:</strong> <?php echo number_format($order['paid_amount'],0,'',' '); ?> ₸</p>
    <p><strong>Остаток к оплате:</strong> <?php echo number_format($debt,0,'',' '); ?> ₸</p>
    <p><strong>Статус:</strong> <?php echo htmlspecialchars($order['status']); ?> / <?php echo htmlspecialchars($order['payment_status']); ?></p>
  </div>

  <?php if(!empty($order['comment'])): ?>
    <div class="act-comment">
      <h2>Комментарий</h2>
      <p><?php echo nl2br(htmlspecialchars($order['comment'])); ?></p>
    </div>
  <?php endif; ?>

  <div class="signatures">
    <div><span></span><p>Передал</p></div>
    <div><span></span><p>Принял</p></div>
  </div>
</section>
