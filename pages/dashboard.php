<?php
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');
$product_filter = $_GET['product'] ?? '';

$inventory_list = $db->query("SELECT type FROM inventory ORDER BY type")->fetchAll(PDO::FETCH_ASSOC);

$where_orders = "o.date_start BETWEEN :start AND :end";
$params_orders = [':start' => $start_date, ':end' => $end_date];

$join_items = "JOIN order_items i ON o.id = i.order_id";
if ($product_filter !== '') {
    $where_orders .= " AND i.inventory_type = :product";
    $params_orders[':product'] = $product_filter;
}

// 1. Issued M2
$stmt = $db->prepare("SELECT SUM(i.m2) FROM orders o $join_items WHERE $where_orders");
$stmt->execute($params_orders);
$period_m2 = (int)$stmt->fetchColumn();

// 2. Gross Income
$stmt = $db->prepare("SELECT SUM(o.deposit + o.paid_amount) FROM orders o WHERE o.date_start BETWEEN :start AND :end" . 
  ($product_filter !== '' ? " AND EXISTS(SELECT 1 FROM order_items WHERE order_id = o.id AND inventory_type = :product)" : ""));
$stmt->execute($params_orders);
$gross_income = (int)$stmt->fetchColumn();

// 3. Expected Remaining Money
$stmt = $db->prepare("SELECT id, m2, days, price_per_m2, deposit, paid_amount FROM orders o WHERE o.date_start BETWEEN :start AND :end AND o.status != 'Возвращено'" . 
  ($product_filter !== '' ? " AND EXISTS(SELECT 1 FROM order_items WHERE order_id = o.id AND inventory_type = :product)" : ""));
$stmt->execute($params_orders);
$unreturned_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$money_expected = 0;
foreach($unreturned_orders as $uo) {
    // get items to calculate rent
    $items = $db->query("SELECT * FROM order_items WHERE order_id = " . $uo['id'])->fetchAll(PDO::FETCH_ASSOC);
    $rent = 0;
    foreach($items as $it) {
        $rent += (int)$it['m2'] * (int)$uo['days'] * (int)$it['price_per_m2'];
    }
    if (empty($items)) {
        $rent = (int)$uo['m2'] * (int)$uo['days'] * (int)$uo['price_per_m2'];
    }
    $debt = max(0, $rent - (int)$uo['deposit'] - (int)$uo['paid_amount']);
    $money_expected += $debt;
}

// 4. Expenses
if ($product_filter === '') {
    $stmt = $db->prepare("SELECT SUM(amount) FROM expenses WHERE expense_date BETWEEN :start AND :end");
    $stmt->execute([':start' => $start_date, ':end' => $end_date]);
    $period_expenses = (int)$stmt->fetchColumn();
} else {
    $period_expenses = 0;
}

$net_profit = $gross_income - $period_expenses;

// 5. Soon to return
$p_soon = [':start' => date('Y-m-d'), ':soon' => date('Y-m-d', strtotime('+7 days'))];
$q_soon = "SELECT o.* FROM orders o WHERE o.date_end BETWEEN :start AND :soon AND o.status != 'Возвращено'";
if ($product_filter !== '') { $q_soon .= " AND EXISTS(SELECT 1 FROM order_items WHERE order_id = o.id AND inventory_type = :product)"; $p_soon[':product'] = $product_filter; }
$q_soon .= " ORDER BY o.date_end";
$stmt = $db->prepare($q_soon);
$stmt->execute($p_soon);
$soon = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. Overdue
$p_overdue = [':today' => date('Y-m-d')];
$q_overdue = "SELECT o.* FROM orders o WHERE o.date_end < :today AND o.status != 'Возвращено'";
if ($product_filter !== '') { $q_overdue .= " AND EXISTS(SELECT 1 FROM order_items WHERE order_id = o.id AND inventory_type = :product)"; $p_overdue[':product'] = $product_filter; }
$stmt = $db->prepare($q_overdue);
$stmt->execute($p_overdue);
$overdue = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 7. Most popular
$stmt = $db->prepare("SELECT i.inventory_type, COUNT(DISTINCT o.id) as cnt, SUM(i.m2) as sum_m2 FROM orders o JOIN order_items i ON o.id = i.order_id WHERE o.date_start BETWEEN :start AND :end GROUP BY i.inventory_type ORDER BY cnt DESC LIMIT 5");
$stmt->execute([':start' => $start_date, ':end' => $end_date]);
$popular = $stmt->fetchAll(PDO::FETCH_ASSOC);
$max_pop = !empty($popular) ? max(array_column($popular, 'cnt')) : 1;

// 8. Orders by status
$stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM orders WHERE date_start BETWEEN :start AND :end GROUP BY status");
$stmt->execute([':start' => $start_date, ':end' => $end_date]);
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Data for Charts
$stmt = $db->prepare("SELECT o.date_start, (o.deposit + o.paid_amount) as income, SUM(i.m2) as m2 FROM orders o LEFT JOIN order_items i ON o.id = i.order_id WHERE o.date_start BETWEEN :start AND :end" . 
    ($product_filter !== '' ? " AND EXISTS(SELECT 1 FROM order_items i2 WHERE i2.order_id = o.id AND i2.inventory_type = :product)" : "") . " GROUP BY o.id, o.date_start, o.deposit, o.paid_amount");
$stmt->execute($params_orders);
$period_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($product_filter === '') {
    $stmt = $db->prepare("SELECT expense_date, amount FROM expenses WHERE expense_date BETWEEN :start AND :end");
    $stmt->execute([':start' => $start_date, ':end' => $end_date]);
    $period_expenses_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $period_expenses_list = [];
}

$datesMap = [];
$current = strtotime($start_date);
$end_ts = strtotime($end_date);
$groupFormat = 'Y-m-d';
if (($end_ts - $current) > 100 * 86400) {
    $groupFormat = 'Y-m';
}

while ($current <= $end_ts) {
    $d = date($groupFormat, $current);
    if (!isset($datesMap[$d])) {
        $datesMap[$d] = ['income' => 0, 'expense' => 0, 'm2' => 0];
    }
    $current = strtotime('+1 day', $current);
}

foreach($period_orders as $po) {
    $d = date($groupFormat, strtotime($po['date_start']));
    if (isset($datesMap[$d])) {
        $datesMap[$d]['income'] += $po['income'];
        $datesMap[$d]['m2'] += $po['m2'];
    }
}
foreach($period_expenses_list as $pe) {
    $d = date($groupFormat, strtotime($pe['expense_date']));
    if (isset($datesMap[$d])) {
        $datesMap[$d]['expense'] += $pe['amount'];
    }
}

$labels = array_keys($datesMap);
$income_data = array_column($datesMap, 'income');
$expense_data = array_column($datesMap, 'expense');
$m2_data = array_column($datesMap, 'm2');

$statusColors = [
    'В аренде' => '#14532d',
    'Частично' => '#b45309',
    'Возвращено' => '#647067'
];
?>
<div class="panel">
  <div class="page-header">
    <h1>Панель управления</h1>
  </div>
  
  <form method="get" class="filter-form" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; background:var(--surface-soft); padding: 16px; margin-bottom: 24px; border-radius: var(--radius); border: 1px solid var(--line);">
    <input type="hidden" name="page" value="dashboard">
    <label style="flex: 1; min-width: 140px;">Дата С
      <input type="date" name="start" value="<?php echo htmlspecialchars($start_date); ?>">
    </label>
    <label style="flex: 1; min-width: 140px;">Дата ПО
      <input type="date" name="end" value="<?php echo htmlspecialchars($end_date); ?>">
    </label>
    <label style="flex: 1.5; min-width: 180px;">Товар
      <select name="product">
        <option value="">-- Все товары --</option>
        <?php foreach($inventory_list as $inv): ?>
          <option value="<?php echo htmlspecialchars($inv['type']); ?>" <?php echo $product_filter === $inv['type'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($inv['type']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; width: 100%;">
      <button type="submit" style="min-width: 120px;">Применить</button>
      <button type="button" onclick="setPeriod('today')" class="badge" style="background:var(--line); color:var(--text);">Сегодня</button>
      <button type="button" onclick="setPeriod('week')" class="badge" style="background:var(--line); color:var(--text);">Неделя</button>
      <button type="button" onclick="setPeriod('month')" class="badge" style="background:var(--line); color:var(--text);">Месяц</button>
      <button type="button" onclick="setPeriod('year')" class="badge" style="background:var(--line); color:var(--text);">Год</button>
    </div>
  </form>

  <?php if(count($overdue) > 0): ?>
    <div class="error">Есть просроченные возвраты: <?php echo count($overdue); ?>. Проверьте список ниже.</div>
  <?php endif; ?>

  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 24px;">
    <div class="metric-card">
      <h3>Выдано м² / единиц</h3>
      <p class="big"><?php echo number_format($period_m2, 0, '', ' '); ?></p>
    </div>
    <div class="metric-card">
      <h3>Доходы (Поступления)</h3>
      <p class="big" style="color: var(--accent);"><?php echo number_format($gross_income,0,'',' '); ?> ₸</p>
    </div>
    <?php if($product_filter === ''): ?>
    <div class="metric-card">
      <h3>Расходы</h3>
      <p class="big danger-text"><?php echo number_format($period_expenses,0,'',' '); ?> ₸</p>
    </div>
    <div class="metric-card">
      <h3>Чистая прибыль</h3>
      <p class="big <?php echo $net_profit >= 0 ? 'success-text' : 'danger-text'; ?>"><?php echo number_format($net_profit,0,'',' '); ?> ₸</p>
    </div>
    <?php else: ?>
    <div class="metric-card">
      <h3>Остаток к оплате</h3>
      <p class="big"><?php echo number_format($money_expected,0,'',' '); ?> ₸</p>
    </div>
    <div class="metric-card" style="opacity: 0.5;">
      <h3>Расходы</h3>
      <p style="font-size: 13px; margin: 0;">(Скрыто для отдельного товара)</p>
    </div>
    <?php endif; ?>
  </div>

  <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
    <div class="card" style="margin: 0;">
      <h2>Статусы заказов (за период)</h2>
      <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px;">
        <?php if(empty($statuses)): ?>
          <p class="muted">Нет данных за этот период</p>
        <?php else: ?>
          <?php foreach($statuses as $st): 
            $col = $statusColors[$st['status']] ?? 'var(--accent)';
          ?>
          <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 1px solid var(--line); border-radius: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
              <span style="display: block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo $col; ?>"></span>
              <strong style="font-size: 16px;"><?php echo htmlspecialchars($st['status']); ?></strong>
            </div>
            <span class="badge" style="background: var(--surface-soft); color: var(--text); font-size: 16px;"><?php echo $st['cnt']; ?> шт</span>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin: 0;">
      <h2>Чаще всего берут</h2>
      <div style="display: flex; flex-direction: column; gap: 16px; margin-top: 16px;">
        <?php if(empty($popular)): ?>
          <p class="muted">Нет данных за этот период</p>
        <?php else: ?>
          <?php foreach($popular as $p): 
            $pct = ($p['cnt'] / $max_pop) * 100;
          ?>
          <div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 13px;">
              <strong><?php echo htmlspecialchars($p['inventory_type']); ?></strong>
              <span class="muted"><?php echo $p['cnt']; ?> раз (<?php echo $p['sum_m2']; ?> ед.)</span>
            </div>
            <div style="height: 10px; background: var(--line); border-radius: 5px; overflow: hidden;">
              <div style="height: 100%; width: <?php echo $pct; ?>%; background: var(--accent); border-radius: 5px;"></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <h2>Скоро должны вернуть</h2>
  <ul>
  <?php foreach($soon as $o): ?>
    <li><a href="/?page=order_view&id=<?php echo $o['id']; ?>">Заказ №<?php echo $o['id']; ?></a> — <?php echo htmlspecialchars($o['client_name']); ?> — <?php echo $o['date_end']; ?></li>
  <?php endforeach; ?>
  </ul>

  <h2>Просрочили</h2>
  <ul>
  <?php foreach($overdue as $o): ?>
    <li><a href="/?page=order_view&id=<?php echo $o['id']; ?>">Заказ №<?php echo $o['id']; ?></a> — <?php echo htmlspecialchars($o['client_name']); ?> — <?php echo $o['date_end']; ?></li>
  <?php endforeach; ?>
  </ul>
</div>
 
<div class="page-header"><h1>Графики</h1></div>
<div class="grid">
  <div class="card">
    <h3>Выдача (м² / ед.)</h3>
    <div class="chart-wrapper"><canvas id="m2Chart"></canvas></div>
  </div>
  <div class="card">
    <h3>Финансы (Доходы и Расходы)</h3>
    <div class="chart-wrapper"><canvas id="moneyChart"></canvas></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
window.__labels = <?php echo json_encode($labels); ?>;
window.__m2 = <?php echo json_encode($m2_data); ?>;
window.__income = <?php echo json_encode($income_data); ?>;
window.__expense = <?php echo json_encode($expense_data); ?>;

function setPeriod(type) {
  const form = document.querySelector('.filter-form');
  const start = form.querySelector('[name="start"]');
  const end = form.querySelector('[name="end"]');
  const today = new Date();
  
  const formatDate = (d) => {
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  };

  if (type === 'today') {
    start.value = formatDate(today);
    end.value = formatDate(today);
  } else if (type === 'week') {
    const wAgo = new Date(today);
    wAgo.setDate(today.getDate() - 6);
    start.value = formatDate(wAgo);
    end.value = formatDate(today);
  } else if (type === 'month') {
    start.value = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-01';
    end.value = formatDate(new Date(today.getFullYear(), today.getMonth()+1, 0));
  } else if (type === 'year') {
    start.value = today.getFullYear() + '-01-01';
    end.value = today.getFullYear() + '-12-31';
  }
  form.submit();
}

document.addEventListener('DOMContentLoaded', function() {
  if (typeof Chart !== 'undefined') {
    var opts = { responsive: true, maintainAspectRatio: false };
    
    var ctxM2 = document.getElementById('m2Chart');
    if (ctxM2) {
      new Chart(ctxM2.getContext('2d'), {
        type: 'line', 
        data: {
          labels: window.__labels, 
          datasets: [{
            label: 'Выдано м²', 
            data: window.__m2, 
            borderColor: '#2563eb', 
            backgroundColor: 'rgba(37, 99, 235, 0.1)',
            fill: true,
            tension: 0.3
          }]
        }, 
        options: opts
      });
    }
    
    var ctxMoney = document.getElementById('moneyChart');
    if (ctxMoney) {
      new Chart(ctxMoney.getContext('2d'), {
        type: 'bar', 
        data: {
          labels: window.__labels, 
          datasets: [
            {
              label: 'Доходы ₸', 
              data: window.__income, 
              backgroundColor: '#16a34a'
            },
            {
              label: 'Расходы ₸', 
              data: window.__expense, 
              backgroundColor: '#dc2626'
            }
          ]
        }, 
        options: Object.assign({}, opts, {
          scales: {
            x: { stacked: true },
            y: { stacked: true }
          }
        })
      });
    }
  }
});
</script>
