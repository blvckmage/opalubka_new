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

function ovTotal(array $o, array $items): int {
  $rent = 0;
  $days = (int)$o['days'];
  foreach($items as $item) {
      $rent += (int)$item['m2'] * $days * (int)$item['price_per_m2'];
  }
  $tax = (int)round($rent * ((int)($o['tax_percentage'] ?? 0)) / 100);
  $delivery = (int)($o['delivery_fee'] ?? 0);
  $discount = (int)round($rent * ((int)($o['discount_percentage'] ?? 0)) / 100);
  return max(0, $rent + $tax - $discount);
}
function ovDebt(array $o, array $items): int {
  return max(0, ovTotal($o, $items) - (int)$o['deposit'] - (int)$o['paid_amount']);
}
function ovPaymentStatus(array $o, array $items): string {
  $debt = ovDebt($o, $items);
  if ($debt <= 0) return 'Оплачено';
  if ((int)$o['deposit'] > 0 || (int)$o['paid_amount'] > 0) return 'Частично';
  return 'Не оплачено';
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'return') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $amount = (int)($_POST['return_m2'] ?? 0);
    
    $item = null;
    foreach($order_items as $it) {
        if ($it['id'] == $item_id) { $item = $it; break; }
    }
    
    if (!$item) {
        $error = "Товар не найден";
    } else {
        $remaining = max(0, (int)$item['m2'] - (int)$item['returned_m2']);
        if ($amount <= 0 || $amount > $remaining) {
          $error = "Укажите возврат от 1 до {$remaining} ед.";
        } else {
          $newReturned = (int)$item['returned_m2'] + $amount;
          $db->prepare("UPDATE order_items SET returned_m2 = :returned WHERE id = :id")
             ->execute([':returned' => $newReturned, ':id' => $item_id]);
             
          // update order status
          $all_returned = true;
          $any_returned = false;
          foreach($order_items as $it) {
              $ret = $it['id'] == $item_id ? $newReturned : (int)$it['returned_m2'];
              if ($ret < (int)$it['m2']) $all_returned = false;
              if ($ret > 0) $any_returned = true;
          }
          $newStatus = $all_returned ? 'Возвращено' : ($any_returned ? 'Частично возвращено' : 'В аренде');
          
          $db->prepare("UPDATE orders SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
             ->execute([':status' => $newStatus, ':id' => $id]);
             
          $db->prepare("INSERT INTO inventory_movements (inventory_type, delta_m2, reason, related_order_id) VALUES (:type, :delta, 'возврат', :oid)")
             ->execute([':type' => $item['inventory_type'], ':delta' => $amount, ':oid' => $id]);
             
          $db->prepare('UPDATE inventory SET total_m2 = total_m2 + :m2 WHERE type = :type')
             ->execute([':m2' => $amount, ':type' => $item['inventory_type']]);
             
          header('Location: /?page=order_view&id=' . $id . '&returned=1');
          exit;
        }
    }
  }


  if ($action === 'discount') {
    $discount_percentage = max(0, (int)($_POST['discount_percentage'] ?? 0));
    $delivery_fee = max(0, (int)($_POST['delivery_fee'] ?? 0));
    $tax_percentage = max(0, (int)($_POST['tax_percentage'] ?? 0));
    $referral_client_id = $_POST['referral_client_id'] ?: null;
    $referral_client_name = trim($_POST['referral_client_name'] ?? '');
    $referral_client_phone = trim($_POST['referral_client_phone'] ?? '');

    if ($referral_client_id === 'new' && $referral_client_name !== '') {
        $stmt = $db->prepare("INSERT INTO clients (name, phone) VALUES (:name, :phone)");
        $stmt->execute([':name'=>$referral_client_name, ':phone'=>$referral_client_phone]);
        $referral_client_id = $db->lastInsertId();
    } elseif ($referral_client_id === 'new') {
        $referral_client_id = null;
    }

    $order['discount_percentage'] = $discount_percentage;
    $order['delivery_fee'] = $delivery_fee;
    $order['tax_percentage'] = $tax_percentage;
    $order['referral_client_id'] = $referral_client_id;
    $paymentStatus = ovPaymentStatus($order, $order_items);
    
    $db->prepare("UPDATE orders SET discount_percentage = :discount, delivery_fee = :delivery, tax_percentage = :tax, referral_client_id = :ref_cid, payment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
       ->execute([':discount' => $discount_percentage, ':delivery' => $delivery_fee, ':tax' => $tax_percentage, ':ref_cid' => $referral_client_id, ':status' => $paymentStatus, ':id' => $id]);
    header('Location: /?page=order_view&id=' . $id . '&discount_updated=1');
    exit;
  }

  if ($action === 'upload' && !empty($_FILES['files']['name'][0])) {
    $uploadDir = __DIR__ . '/../uploads/orders';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    foreach ($_FILES['files']['name'] as $i => $originalName) {
      if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
      $safeExt = pathinfo($originalName, PATHINFO_EXTENSION);
      $fileName = 'order_' . $id . '_' . uniqid('', true) . ($safeExt ? '.' . $safeExt : '');
      if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $uploadDir . '/' . $fileName)) {
        $stmt = $db->prepare('INSERT INTO order_files (order_id, original_name, file_name, file_type) VALUES (:order_id, :original, :file, :type)');
        $stmt->execute([':order_id' => $id, ':original' => $originalName, ':file' => $fileName, ':type' => $_FILES['files']['type'][$i]]);
      }
    }
    header('Location: /?page=order_view&id=' . $id . '&uploaded=1');
    exit;
  }
}

// Recalculate remaining from all items
$total_remaining = 0;
foreach($order_items as $it) {
    $total_remaining += max(0, (int)$it['m2'] - (int)$it['returned_m2']);
}

$total = ovTotal($order, $order_items);
$debt = ovDebt($order, $order_items);

$stmt = $db->prepare('SELECT * FROM inventory_movements WHERE related_order_id = :id ORDER BY created_at DESC');
$stmt->execute([':id' => $id]);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare('SELECT * FROM order_files WHERE order_id = :id ORDER BY created_at DESC');
$stmt->execute([':id' => $id]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

$clients = $db->query('SELECT * FROM clients')->fetchAll(PDO::FETCH_ASSOC);

$referralClient = null;
if (!empty($order['referral_client_id'])) {
    $stmt = $db->prepare('SELECT name FROM clients WHERE id = :id');
    $stmt->execute([':id' => $order['referral_client_id']]);
    $referralClient = $stmt->fetchColumn();
}
?>
<div class="card">
  <div class="page-header">
    <h1>Заказ №<?php echo $order['id']; ?></h1>
    <div class="actions">
      <a class="badge" href="/?page=orders">Все заказы</a>
      <a class="badge" href="/?page=order_act&id=<?php echo $order['id']; ?>">Акт/PDF</a>
    </div>
  </div>
  <?php if(!empty($_GET['returned'])): ?><div class="notice">Возврат принят</div><?php endif; ?>
  <?php if(!empty($_GET['paid'])): ?><div class="notice">Оплата обновлена</div><?php endif; ?>
  <?php if(!empty($_GET['uploaded'])): ?><div class="notice">Файлы добавлены</div><?php endif; ?>
  <?php if(!empty($_GET['discount_updated'])): ?><div class="notice">Скидка обновлена</div><?php endif; ?>
  <?php if($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <div class="grid">
    <div class="metric-card"><h3>Сумма</h3><p class="big"><?php echo number_format($total,0,'',' '); ?> ₸</p></div>
    <div class="metric-card"><h3>Остаток к оплате</h3><p class="big"><?php echo number_format($debt,0,'',' '); ?> ₸</p></div>
    <div class="metric-card"><h3>Осталось вернуть</h3><p class="big"><?php echo $total_remaining; ?> ед.</p></div>
  </div>

  <div class="details-list">
    <p><strong>Клиент:</strong> <?php echo htmlspecialchars($order['client_name']); ?>, <?php echo htmlspecialchars($order['client_phone']); ?></p>
    <p><strong>Объект:</strong> <?php echo htmlspecialchars($order['address']); ?></p>
    <p><strong>Срок:</strong> <?php echo $order['date_start']; ?> - <?php echo $order['date_end']; ?> (<?php echo $order['days']; ?> дн.)</p>
    <p><strong>Статус:</strong> <?php echo htmlspecialchars($order['status']); ?> / <?php echo htmlspecialchars($order['payment_status']); ?></p>
    <?php if(!empty($order['delivery_fee'])): ?>
    <p><strong>Доставка:</strong> <?php echo (int)($order['delivery_fee']??0); ?> ₸</p>
    <?php endif; ?>
    <?php if(!empty($order['tax_percentage'])): ?>
    <p><strong>Налог (Юр.лицо):</strong> <?php echo (int)($order['tax_percentage']??0); ?> %</p>
    <?php endif; ?>
    <?php if(!empty($order['discount_percentage']) || $referralClient): ?>
    <p><strong>Скидка:</strong> <?php echo (int)($order['discount_percentage']??0); ?> % 
      <?php if($referralClient) echo '(Привел(а): '.htmlspecialchars($referralClient).')'; ?>
    </p>
    <?php endif; ?>
  </div>

  <h3 style="margin-top: 24px; margin-bottom: 12px;">Товары в заказе</h3>
  <table>
      <thead><tr><th>Товар</th><th>Выдано</th><th>Возвращено</th><th>Цена в день</th></tr></thead>
      <tbody>
          <?php foreach($order_items as $item): ?>
          <tr>
              <td data-label="Товар"><strong><?php echo htmlspecialchars($item['inventory_type']); ?></strong></td>
              <td data-label="Выдано"><?php echo $item['m2']; ?></td>
              <td data-label="Возвращено"><?php echo $item['returned_m2']; ?></td>
              <td data-label="Цена в день"><?php echo number_format($item['price_per_m2'], 0, '', ' '); ?> ₸</td>
          </tr>
          <?php endforeach; ?>
      </tbody>
  </table>
</div>



<div class="card">
  <div class="page-header"><h1>Скидка, Доставка и Налог</h1></div>
  <form method="post" class="form-grid compact-form" id="discountForm">
    <input type="hidden" name="action" value="discount">
    <label>Сумма за доставку (в ₸)
      <input name="delivery_fee" type="number" inputmode="numeric" min="0" value="<?php echo (int)($order['delivery_fee'] ?? 0); ?>">
    </label>
    <label>Налог на Юр.лицо (в %)
      <input name="tax_percentage" type="number" inputmode="numeric" min="0" value="<?php echo (int)($order['tax_percentage'] ?? 0); ?>">
    </label>
    <label>Скидка (в % от суммы аренды)
      <input name="discount_percentage" type="number" inputmode="numeric" min="0" max="100" value="<?php echo (int)($order['discount_percentage'] ?? 0); ?>">
    </label>
    <label>Приведенный клиент (Реферал)
        <select name="referral_client_id" id="referral_client_id">
            <option value="">Нет</option>
            <option value="new">Новый клиент</option>
            <?php foreach($clients as $c){ $sel = ($order['referral_client_id']==$c['id'])? 'selected':''; echo "<option value=\"{$c['id']}\" $sel>".htmlspecialchars($c['name'])."</option>";} ?>
        </select>
    </label>
    <div class="inline-panel full" id="newReferralClientPanel" hidden>
        <h2>Новый приведенный клиент</h2>
        <div class="form-grid">
            <label>Имя клиента
                <input name="referral_client_name" id="referral_client_name" value="">
            </label>
            <label>Телефон
                <input name="referral_client_phone" id="referral_client_phone" inputmode="tel" value="">
            </label>
        </div>
    </div>
    <div class="full"><button>Сохранить скидку</button></div>
  </form>
</div>

<div class="card">
  <div class="page-header"><h1>Частичный возврат</h1></div>
  <?php if($total_remaining > 0): ?>
    <form method="post" class="form-grid compact-form">
      <input type="hidden" name="action" value="return">
      <label class="full">Выберите товар для возврата
          <select name="item_id" required>
              <option value="">-- Выберите товар --</option>
              <?php foreach($order_items as $item): 
                  $rem = $item['m2'] - $item['returned_m2'];
                  if ($rem > 0): ?>
                  <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['inventory_type']); ?> (Осталось <?php echo $rem; ?> ед.)</option>
              <?php endif; endforeach; ?>
          </select>
      </label>
      <label>Вернули (кол-во)
        <input name="return_m2" type="number" inputmode="numeric" min="1" placeholder="1">
      </label>
      <div class="full"><button>Принять возврат</button></div>
    </form>
  <?php else: ?>
    <p class="muted">Заказ полностью возвращен.</p>
  <?php endif; ?>
</div>

<div class="card">
  <div class="page-header"><h1>Файлы</h1></div>
  <form method="post" enctype="multipart/form-data" class="form-grid compact-form">
    <input type="hidden" name="action" value="upload">
    <label class="full">Договор, накладная, фото объекта
      <input name="files[]" type="file" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx">
    </label>
    <div class="full"><button>Загрузить файлы</button></div>
  </form>
  <ul class="file-list">
    <?php foreach($files as $file): ?>
      <li><a href="/uploads/orders/<?php echo htmlspecialchars($file['file_name']); ?>" target="_blank"><?php echo htmlspecialchars($file['original_name']); ?></a> <span class="muted"><?php echo $file['created_at']; ?></span></li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="card">
  <div class="page-header"><h1>История движения</h1></div>
  <table>
    <thead><tr><th>Когда</th><th>Кол-во</th><th>Причина</th></tr></thead>
    <tbody>
      <?php foreach($movements as $m): ?>
        <tr><td data-label="Когда"><?php echo $m['created_at']; ?></td><td data-label="Кол-во"><?php echo $m['delta_m2']; ?></td><td data-label="Причина"><?php echo htmlspecialchars($m['reason']); ?> (<?php echo htmlspecialchars($m['inventory_type']); ?>)</td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
