<?php
// create order form and handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_POST['client_id'] ?: null;
    $client_name = trim($_POST['client_name'] ?? '');
    $client_phone = trim($_POST['client_phone'] ?? '');
    $address = $_POST['address'] ?: '';
    $inventory_type = $_POST['inventory_type'] ?: '';
    $m2 = (int)$_POST['m2'];
    $days = (int)$_POST['days'];
    $price = (int)$_POST['price'];
    $date_start = $_POST['date_start'] ?: date('Y-m-d');
    $date_end = date('Y-m-d', strtotime($date_start . " +{$days} days"));
    $deposit = (int)$_POST['deposit'];
    $paid_amount = (int)($_POST['paid_amount'] ?? 0);
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

    if (empty($error)) {
        $stmt = $db->prepare("SELECT total_m2 FROM inventory WHERE type = :type");
        $stmt->execute([':type'=>$inventory_type]);
        $avail = (int)$stmt->fetchColumn();
        if ($m2 > $avail) {
            $error = "Недостаточно опалубки на складе. Доступно: {$avail} м²";
        } else {
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

            $rent = $m2 * $days * $price;
            $tax = round($rent * $tax_percentage / 100);
            $discount_amount = round($rent * $discount_percentage / 100);
            $total = max(0, $rent + $tax + $delivery_fee - $discount_amount);
            $debt = max(0, $total - $deposit - $paid_amount);
            $payment_status = 'Не оплачено';
            if ($debt <= 0) {
                $payment_status = 'Оплачено';
            } elseif ($deposit > 0 || $paid_amount > 0) {
                $payment_status = 'Частично';
            }

            $stmt = $db->prepare("INSERT INTO orders (client_id, client_name, client_phone, address, inventory_type, m2, days, price_per_m2, discount_amount, discount_percentage, delivery_fee, tax_percentage, referral_client_id, date_start, date_end, deposit, paid_amount, payment_status, comment) VALUES (:cid,:cname,:cphone,:addr,:type,:m2,:days,:price,:discount_amt,:discount_pct,:delivery,:tax,:ref_cid,:ds,:de,:dep,:paid,:pay_status,:com)");
            $stmt->execute([
                ':cid'=>$client_id,':cname'=>$client_name,':cphone'=>$client_phone,':addr'=>$address,':type'=>$inventory_type,':m2'=>$m2,':days'=>$days,':price'=>$price,':discount_amt'=>$discount_amount,':discount_pct'=>$discount_percentage,':delivery'=>$delivery_fee,':tax'=>$tax_percentage,':ref_cid'=>$referral_client_id,':ds'=>$date_start,':de'=>$date_end,':dep'=>$deposit,':paid'=>$paid_amount,':pay_status'=>$payment_status,':com'=>$comment
            ]);
            $order_id = $db->lastInsertId();

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

            $stmt = $db->prepare("INSERT INTO inventory_movements (inventory_type, delta_m2, reason, related_order_id) VALUES (:type, :delta, 'выдача', :oid)");
            $stmt->execute([':type'=>$inventory_type, ':delta'=>-$m2, ':oid'=>$order_id]);

            $stmt = $db->prepare("UPDATE inventory SET total_m2 = total_m2 - :m2 WHERE type = :type");
            $stmt->execute([':m2'=>$m2, ':type'=>$inventory_type]);

            header('Location: /?page=orders');
            exit;
        }
    }
}

// fetch clients and inventory types
$clients = $db->query('SELECT * FROM clients')->fetchAll(PDO::FETCH_ASSOC);
$inventory = $db->query('SELECT * FROM inventory')->fetchAll(PDO::FETCH_ASSOC);
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
        <label class="full">Вид опалубки
            <select name="inventory_type" id="inventory_type"><?php 
            foreach($inventory as $it){ 
                $sel = (!empty($_POST['inventory_type']) && $_POST['inventory_type']==$it['type'])? 'selected':''; 
                $s_p = $it['price'] ?? 0;
                $s_u = $it['unit'] ?? 'ед.';
                echo "<option value=\"".htmlspecialchars($it['type'])."\" $sel data-avail=\"{$it['total_m2']}\" data-price=\"{$s_p}\" data-unit=\"".htmlspecialchars($s_u)."\">".htmlspecialchars($it['type'])."</option>";
            } ?></select>
        </label>
        <div class="full"><div class="available" id="availableInfo">Доступно: — ед.</div></div>
        <label>Количество (<span class="dyn-unit">м²</span>)
            <input name="m2" id="m2" type="number" inputmode="numeric" min="1" value="<?php echo htmlspecialchars($_POST['m2'] ?? ''); ?>">
        </label>
        <label>Количество дней
            <input name="days" id="days" type="number" inputmode="numeric" min="1" value="<?php echo htmlspecialchars($_POST['days'] ?? ''); ?>">
        </label>
        <label>Цена за 1 <span class="dyn-unit">м²</span> в день
            <input name="price" id="price" type="number" inputmode="numeric" min="1" value="<?php echo htmlspecialchars($_POST['price'] ?? ''); ?>">
        </label>
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
        <label>Залог
            <input name="deposit" id="deposit" type="number" inputmode="numeric" min="0" value="<?php echo htmlspecialchars($_POST['deposit'] ?? ''); ?>">
        </label>
        <label>Оплачено сверх залога
            <input name="paid_amount" id="paid_amount" type="number" inputmode="numeric" min="0" value="<?php echo htmlspecialchars($_POST['paid_amount'] ?? ''); ?>">
        </label>
        <div class="sum-box full">
            <span>Итоговая сумма</span>
            <strong><span id="sumCalc">0</span> ₸</strong>
        </div>
        <div class="muted small full">Считается как: (кол-во × дни × цена) + налог + доставка - скидка.</div>
        <label>Дата выдачи
            <input name="date_start" id="date_start" type="date" value="<?php echo htmlspecialchars($_POST['date_start'] ?? date('Y-m-d')); ?>">
        </label>
        <!-- дата возврата вычисляется автоматически -->
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
    // set initial available info
    (function(){
        var sel = document.getElementById('inventory_type');
        var avail = document.getElementById('availableInfo');
        if (!sel || !avail) return;
        function upd(){
            var opt = sel.options[sel.selectedIndex];
            var a = opt ? opt.getAttribute('data-avail') : '—';
            var u = opt ? opt.getAttribute('data-unit') : 'ед.';
            avail.textContent = 'Доступно: ' + a + ' ' + u;
        }
        sel.addEventListener('change', upd);
        upd();
    })();
</script>
