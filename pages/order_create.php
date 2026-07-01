<?php
// create order form and handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_POST['client_id'] ?: null;
    $client_name = trim($_POST['client_name'] ?? '');
    $client_phone = trim($_POST['client_phone'] ?? '');
    $address = $_POST['address'] ?: '';
    
    $days = (int)$_POST['days'];
    $date_start = $_POST['date_start'] ?: date('Y-m-d');
    $date_end = date('Y-m-d', strtotime($date_start . " +{$days} days"));
    $deposit = 0;
    $paid_amount = 0;
    $discount_percentage = (int)($_POST['discount_percentage'] ?? 0);
    $delivery_fee = (int)($_POST['delivery_fee'] ?? 0);
    $tax_percentage = (int)($_POST['tax_percentage'] ?? 0);
    $has_referral = !empty($_POST['has_referral']);
    $referral_client_id = $_POST['referral_client_id'] ?: null;
    $referral_client_name = trim($_POST['referral_client_name'] ?? '');
    $referral_client_phone = trim($_POST['referral_client_phone'] ?? '');
    
    if (!$has_referral) {
        $referral_client_id = null;
        $discount_percentage = 0;
    }
    if (($_POST['delivery_type'] ?? '') !== 'delivery') {
        $delivery_fee = 0;
    }
    $client_type = $_POST['client_type'] ?? 'Физ.лицо';
    $comment = $_POST['comment'] ?: '';

    $error = '';

    if ($client_id) {
        $stmt = $db->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute([':id' => $client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($client) {
            $client_name = $client['name'];
            $client_phone = $client['phone'];
        } else {
            $error = 'Выбранный клиент не найден';
        }
    } elseif ($client_name === '') {
        $error = 'Укажите имя нового клиента';
    }

    $inventory = $db->query('SELECT * FROM inventory')->fetchAll(PDO::FETCH_ASSOC);
    
    $items = $_POST['items'] ?? [];
    $valid_items = [];
    $total_rent = 0;
    $first_inventory_type = '';
    $first_m2 = 0;
    $first_price = 0;

    foreach($inventory as $inv) {
        $type = $inv['type'];
        if (isset($items[$type])) {
            $qty = (int)($items[$type]['m2'] ?? 0);
            $price = (int)($items[$type]['price'] ?? $inv['price']);
            if ($qty > 0) {
                if ($qty > $inv['total_m2']) {
                    $error = "Недостаточно товара: {$type}. Доступно: {$inv['total_m2']}";
                    break;
                }
                $valid_items[] = ['type' => $type, 'm2' => $qty, 'price' => $price];
                $total_rent += $qty * $days * $price;
                if ($first_inventory_type === '') {
                    $first_inventory_type = $type;
                    $first_m2 = $qty;
                    $first_price = $price;
                }
            }
        }
    }

    if (empty($error) && empty($valid_items)) {
        $error = "Не выбран ни один товар";
    }

    if (empty($error)) {
        if (!$client_id) {
            $stmt = $db->prepare("INSERT INTO clients (name, phone, client_type) VALUES (:name, :phone, :type)");
            $stmt->execute([':name'=>$client_name, ':phone'=>$client_phone, ':type'=>$client_type]);
            $client_id = $db->lastInsertId();
        }

        if ($referral_client_id === 'new' && $referral_client_name !== '') {
            $stmt = $db->prepare("INSERT INTO clients (name, phone) VALUES (:name, :phone)");
            $stmt->execute([':name'=>$referral_client_name, ':phone'=>$referral_client_phone]);
            $referral_client_id = $db->lastInsertId();
        } elseif ($referral_client_id === 'new') {
            $referral_client_id = null;
        }

        $tax = round($total_rent * $tax_percentage / 100);
        $discount_amount = round($total_rent * $discount_percentage / 100);
        $total = max(0, $total_rent + $tax - $discount_amount);
        $debt = max(0, $total - $deposit - $paid_amount);
        $payment_status = 'Не оплачено';
        if ($debt <= 0) {
            $payment_status = 'Оплачено';
        } elseif ($deposit > 0 || $paid_amount > 0) {
            $payment_status = 'Частично';
        }

        // inventory_type, m2, price_per_m2 in orders table are deprecated but kept for backwards compatibility.
        // We'll just put the first item's details there so old queries don't crash entirely.
        if (count($valid_items) > 1) {
            $first_inventory_type = "Сборный заказ";
        }

        $stmt = $db->prepare("INSERT INTO orders (client_id, client_name, client_phone, address, inventory_type, m2, days, price_per_m2, discount_amount, discount_percentage, delivery_fee, tax_percentage, referral_client_id, date_start, date_end, deposit, paid_amount, payment_status, comment) VALUES (:cid,:cname,:cphone,:addr,:type,:m2,:days,:price,:discount_amt,:discount_pct,:delivery,:tax,:ref_cid,:ds,:de,:dep,:paid,:pay_status,:com)");
        $stmt->execute([
            ':cid'=>$client_id,':cname'=>$client_name,':cphone'=>$client_phone,':addr'=>$address,
            ':type'=>$first_inventory_type,':m2'=>$first_m2,':days'=>$days,':price'=>$first_price,
            ':discount_amt'=>$discount_amount,':discount_pct'=>$discount_percentage,':delivery'=>$delivery_fee,
            ':tax'=>$tax_percentage,':ref_cid'=>$referral_client_id,':ds'=>$date_start,':de'=>$date_end,
            ':dep'=>$deposit,':paid'=>$paid_amount,':pay_status'=>$payment_status,':com'=>$comment
        ]);
        $order_id = $db->lastInsertId();

        if ($delivery_fee > 0) {
            $stmt = $db->prepare("SELECT id FROM expense_categories WHERE name = 'Доставка'");
            $stmt->execute();
            $cat_id = $stmt->fetchColumn();
            if (!$cat_id) {
                $db->prepare("INSERT INTO expense_categories (name) VALUES ('Доставка')")->execute();
                $cat_id = $db->lastInsertId();
            }
            $stmt = $db->prepare("INSERT INTO expenses (category_id, amount, description, expense_date) VALUES (:cid, :amount, :desc, :dt)");
            $stmt->execute([
                ':cid' => $cat_id,
                ':amount' => $delivery_fee,
                ':desc' => "Доставка по заказу #" . $order_id . " (" . $client_name . ")",
                ':dt' => $date_start
            ]);
        }

        // Insert order items and movements
        $stmt_item = $db->prepare("INSERT INTO order_items (order_id, inventory_type, m2, price_per_m2) VALUES (:oid, :type, :m2, :price)");
        $stmt_mov = $db->prepare("INSERT INTO inventory_movements (inventory_type, delta_m2, reason, related_order_id) VALUES (:type, :delta, 'выдача', :oid)");
        $stmt_upd = $db->prepare("UPDATE inventory SET total_m2 = total_m2 - :m2 WHERE type = :type");

        foreach($valid_items as $item) {
            $stmt_item->execute([':oid'=>$order_id, ':type'=>$item['type'], ':m2'=>$item['m2'], ':price'=>$item['price']]);
            $stmt_mov->execute([':type'=>$item['type'], ':delta'=>-$item['m2'], ':oid'=>$order_id]);
            $stmt_upd->execute([':m2'=>$item['m2'], ':type'=>$item['type']]);
        }

        if (!empty($_FILES['files']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/orders';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            foreach ($_FILES['files']['name'] as $i => $originalName) {
                if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $safeExt = pathinfo($originalName, PATHINFO_EXTENSION);
                $fileName = 'order_' . $order_id . '_' . uniqid('', true) . ($safeExt ? '.' . $safeExt : '');
                if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $uploadDir . '/' . $fileName)) {
                    $stmt = $db->prepare('INSERT INTO order_files (order_id, original_name, file_name, file_type) VALUES (:order_id, :original, :file, :type)');
                    $stmt->execute([':order_id'=>$order_id, ':original'=>$originalName, ':file'=>$fileName, ':type'=>$_FILES['files']['type'][$i]]);
                }
            }
        }

        header('Location: /?page=orders');
        exit;
    }
} else {
    $inventory = $db->query('SELECT * FROM inventory')->fetchAll(PDO::FETCH_ASSOC);
}

// fetch clients
$clients = $db->query('SELECT * FROM clients')->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
    <div class="page-header"><h1>Новая аренда</h1></div>
    <?php if(!empty($error)) echo "<div class='error'>".htmlspecialchars($error)."</div>"; ?>
    <form method="post" id="orderForm" class="form-grid" enctype="multipart/form-data">
        <label class="full">Клиент
            <select name="client_id" id="client_id">
                <option value="">Новый клиент</option>
                <?php foreach($clients as $c){ $sel = (!empty($_POST['client_id']) && $_POST['client_id']==$c['id'])? 'selected':''; echo "<option value=\"{$c['id']}\" $sel data-name=\"".htmlspecialchars($c['name'])."\" data-phone=\"".htmlspecialchars($c['phone'])."\" data-type=\"".htmlspecialchars($c['client_type']??'Физ.лицо')."\">".htmlspecialchars($c['name'])."</option>";} ?>
            </select>
        </label>
        <div class="inline-panel full" id="newClientPanel">
            <h2>Новый клиент</h2>
            <div class="form-grid">
                <label>Имя клиента
                    <input name="client_name" id="client_name" autocomplete="name" value="<?php echo htmlspecialchars($_POST['client_name'] ?? ''); ?>">
                </label>
                <label>Телефон
                    <input name="client_phone" id="client_phone" inputmode="tel" autocomplete="tel" value="<?php echo htmlspecialchars($_POST['client_phone'] ?? ''); ?>">
                </label>
                <label>Тип лица
                    <select name="client_type">
                        <option value="Физ.лицо">Физ.лицо</option>
                        <option value="Юр.лицо">Юр.лицо</option>
                    </select>
                </label>
            </div>
        </div>
        <label class="full">Объект/адрес
            <input name="address" id="address" autocomplete="street-address" value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
        </label>
        
        <label class="full" style="margin-top: 1rem;">Товары в аренду (Корзина)</label>
        <div id="itemsContainer" class="full" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 10px;">
            <!-- Dynamic items will go here -->
        </div>
        <div class="full">
            <button type="button" id="addItemBtn" class="badge" style="width: 100%; border: 1px dashed var(--accent); background: transparent; color: var(--accent);">+ Добавить товар</button>
        </div>

        <label>Количество дней
            <input name="days" id="days" type="number" inputmode="numeric" min="1" value="<?php echo htmlspecialchars($_POST['days'] ?? ''); ?>">
        </label>

        <label class="full compact-checkbox">
            <input type="checkbox" name="has_referral" id="has_referral" value="1" <?php if(!empty($_POST['has_referral'])) echo 'checked'; ?>> Привел клиента (Реферал)
        </label>
        <div class="inline-panel full" id="referralPanel" style="display:none;">
            <div class="form-grid">
                <label>Приведенный клиент
                    <select name="referral_client_id" id="referral_client_id">
                        <option value="">Нет</option>
                        <option value="new" <?php if(($_POST['referral_client_id']??'')==='new') echo 'selected'; ?>>Новый клиент</option>
                        <?php foreach($clients as $c){ $sel = (!empty($_POST['referral_client_id']) && $_POST['referral_client_id']==$c['id'])? 'selected':''; echo "<option value=\"{$c['id']}\" $sel>".htmlspecialchars($c['name'])."</option>";} ?>
                    </select>
                </label>
                <label>Скидка (в % от суммы аренды)
                    <input name="discount_percentage" id="discount_percentage" type="number" inputmode="numeric" min="0" max="100" value="<?php echo htmlspecialchars($_POST['discount_percentage'] ?? ''); ?>">
                </label>
            </div>
            <div class="form-grid" id="newReferralClientPanel" style="display:none; margin-top:1rem;">
                <label>Имя нового клиента
                    <input name="referral_client_name" id="referral_client_name" value="<?php echo htmlspecialchars($_POST['referral_client_name'] ?? ''); ?>">
                </label>
                <label>Телефон нового клиента
                    <input name="referral_client_phone" id="referral_client_phone" inputmode="tel" value="<?php echo htmlspecialchars($_POST['referral_client_phone'] ?? ''); ?>">
                </label>
            </div>
        </div>

        <label class="full">Способ получения
            <select name="delivery_type" id="delivery_type">
                <option value="pickup" <?php echo (($_POST['delivery_type']??'')==='pickup')?'selected':''; ?>>Самовывоз</option>
                <option value="delivery" <?php echo (($_POST['delivery_type']??'')==='delivery')?'selected':''; ?>>Доставка</option>
            </select>
        </label>
        <div class="inline-panel full" id="deliveryPanel" style="display:none;">
            <label>Сумма за доставку (в ₸)
                <input name="delivery_fee" id="delivery_fee" type="number" inputmode="numeric" min="0" value="<?php echo htmlspecialchars($_POST['delivery_fee'] ?? ''); ?>">
            </label>
        </div>
        <div class="inline-panel full" id="taxPanel" style="display:none;">
            <label>Налог на Юр.лицо (в %)
                <input name="tax_percentage" id="tax_percentage" type="number" inputmode="numeric" min="0" value="<?php echo htmlspecialchars($_POST['tax_percentage'] ?? ''); ?>">
            </label>
        </div>


        <div class="sum-box full" style="flex-direction: column; align-items: stretch; gap: 8px;">
            <div style="display:flex; justify-content:space-between; width: 100%;"><span style="color:#d7e5d9; font-size: 14px; font-weight: normal;">Сумма аренды:</span> <strong style="font-size: 16px;"><span id="sumRent">0</span> ₸</strong></div>
            <div style="display:flex; justify-content:space-between; width: 100%;"><span style="color:#d7e5d9; font-size: 14px; font-weight: normal;">Налог:</span> <strong style="font-size: 16px;"><span id="sumTax">0</span> ₸</strong></div>
            <div style="display:flex; justify-content:space-between; width: 100%;"><span style="color:#d7e5d9; font-size: 14px; font-weight: normal;">Скидка:</span> <strong style="font-size: 16px;">-<span id="sumDiscount">0</span> ₸</strong></div>
            <hr style="border: none; border-bottom: 1px solid rgba(255,255,255,0.2); margin: 4px 0;">
            <div style="display:flex; justify-content:space-between; width: 100%;"><span>Итого к оплате:</span> <strong><span id="sumCalc">0</span> ₸</strong></div>
            <div style="display:flex; justify-content:space-between; width: 100%; margin-top: 6px;"><span style="color:#f87171; font-size: 14px; font-weight: normal;">Расход компании на доставку:</span> <strong style="color:#f87171; font-size: 16px;"><span id="sumDelivery">0</span> ₸</strong></div>
        </div>
        <div class="muted small full">Считается как: (сумма аренды всех товаров × дни) + налог - скидка. Доставка оплачивается компанией.</div>
        
        <label>Дата выдачи
            <input name="date_start" id="date_start" type="date" value="<?php echo htmlspecialchars($_POST['date_start'] ?? date('Y-m-d')); ?>">
        </label>
        <label class="full">Комментарий
            <textarea name="comment" id="comment"><?php echo htmlspecialchars($_POST['comment'] ?? ''); ?></textarea>
        </label>
        <label class="full">Файлы к заказу
            <input name="files[]" type="file" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx">
        </label>
        <div class="full"><button id="submitBtn">Создать аренду</button></div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inventory = <?php echo json_encode($inventory); ?>;
    const container = document.getElementById('itemsContainer');
    const addBtn = document.getElementById('addItemBtn');

    function createItemRow() {
        const row = document.createElement('div');
        row.style = "display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface-soft); flex-wrap: wrap; position: relative;";
        
        let options = '<option value="">-- Выберите товар --</option>';
        inventory.forEach(inv => {
            options += `<option value="${inv.type}" data-price="${inv.price}" data-unit="${inv.unit}" data-max="${inv.total_m2}">${inv.type} (Остаток: ${inv.total_m2} ${inv.unit})</option>`;
        });

        row.innerHTML = `
            <div style="flex: 2; min-width: 150px;">
                <label style="font-size: 11px; margin-bottom: 2px;">Товар</label>
                <select class="item-select" required style="min-height: 38px; margin-top: 0; padding: 6px;">${options}</select>
            </div>
            <div style="flex: 1; min-width: 80px;">
                <label style="font-size: 11px; margin-bottom: 2px;"><span class="unit-label">Кол-во</span></label>
                <input type="number" class="item-qty" min="1" placeholder="0" required style="min-height: 38px; margin-top: 0; padding: 6px;">
            </div>
            <div style="flex: 1; min-width: 90px;">
                <label style="font-size: 11px; margin-bottom: 2px;">Цена/день (₸)</label>
                <input type="number" class="item-price" min="0" required style="min-height: 38px; margin-top: 0; padding: 6px;">
            </div>
            <button type="button" class="remove-btn" style="position: absolute; top: -10px; right: -10px; background: var(--danger); color: white; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 12px; padding: 0; line-height: 24px; text-align: center;">✕</button>
        `;

        container.appendChild(row);

        const select = row.querySelector('.item-select');
        const qty = row.querySelector('.item-qty');
        const price = row.querySelector('.item-price');
        const removeBtn = row.querySelector('.remove-btn');
        const unitLabel = row.querySelector('.unit-label');

        select.addEventListener('change', function() {
            const opt = select.options[select.selectedIndex];
            if (opt.value) {
                qty.name = `items[${opt.value}][m2]`;
                price.name = `items[${opt.value}][price]`;
                price.value = opt.getAttribute('data-price');
                qty.max = opt.getAttribute('data-max');
                unitLabel.textContent = `Кол-во (${opt.getAttribute('data-unit')})`;
                if (window.calc) window.calc();
            } else {
                qty.removeAttribute('name');
                price.removeAttribute('name');
                price.value = '';
                unitLabel.textContent = 'Кол-во';
            }
        });

        qty.addEventListener('input', () => { if (window.calc) window.calc(); });
        price.addEventListener('input', () => { if (window.calc) window.calc(); });
        removeBtn.addEventListener('click', () => {
            row.remove();
            if (window.calc) window.calc();
        });
    }

    if (addBtn) {
        addBtn.addEventListener('click', createItemRow);
        createItemRow(); // Create first row by default
    }
});
</script>
