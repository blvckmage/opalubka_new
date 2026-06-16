<?php
// Dashboard analytics
// compute basic stats
$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-7 days'));

// m2 issued today
$stmt = $db->prepare("SELECT SUM(m2) as sum_m2 FROM orders WHERE date_start = :today");
$stmt->execute([':today'=>$today]);
$today_m2 = $stmt->fetchColumn() ?: 0;

// m2 issued last week
$stmt = $db->prepare("SELECT SUM(m2) as sum_m2 FROM orders WHERE date_start BETWEEN :week AND :today");
$stmt->execute([':week'=>$weekAgo,':today'=>$today]);
$week_m2 = $stmt->fetchColumn() ?: 0;

// money expected (sum of rent for active orders)
$stmt = $db->query("SELECT SUM(CASE WHEN ((m2*days*price_per_m2) - deposit - paid_amount) < 0 THEN 0 ELSE ((m2*days*price_per_m2) - deposit - paid_amount) END) FROM orders WHERE status!='Возвращено'");
$money_expected = $stmt->fetchColumn() ?: 0;

// soon to return (within 7 days)
$stmt = $db->prepare("SELECT * FROM orders WHERE date_end BETWEEN :today AND :soon AND status!='Возвращено' ORDER BY date_end");
$stmt->execute([':today'=>$today,':soon'=>date('Y-m-d', strtotime('+7 days'))]);
$soon = $stmt->fetchAll(PDO::FETCH_ASSOC);

// overdue
$stmt = $db->prepare("SELECT * FROM orders WHERE date_end < :today AND status!='Возвращено'");
$stmt->execute([':today'=>$today]);
$overdue = $stmt->fetchAll(PDO::FETCH_ASSOC);

// most common inventory
$stmt = $db->query("SELECT inventory_type, COUNT(*) as cnt FROM orders GROUP BY inventory_type ORDER BY cnt DESC LIMIT 5");
$popular = $stmt->fetchAll(PDO::FETCH_ASSOC);

// orders by status
$stmt = $db->query("SELECT status, COUNT(*) as cnt FROM orders WHERE status!='Возвращено' GROUP BY status");
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="panel">
  <div class="page-header">
    <h1>Панель управления</h1>
    <div class="actions">
      <span class="badge">Сегодня: <?php echo $today_m2; ?> м²</span>
      <span class="badge">За неделю: <?php echo $week_m2; ?> м²</span>
    </div>
  </div>
  <?php if(count($overdue) > 0): ?>
    <div class="error">Есть просроченные возвраты: <?php echo count($overdue); ?>. Проверьте список ниже.</div>
  <?php endif; ?>

  <div class="grid">
    <div class="metric-card">
      <h3>М² ушло сегодня</h3>
      <p class="big"><?php echo $today_m2; ?> м²</p>
    </div>
    <div class="metric-card">
      <h3>М² за неделю</h3>
      <p class="big"><?php echo $week_m2; ?> м²</p>
    </div>
    <div class="metric-card">
      <h3>Остаток к оплате</h3>
      <p class="big"><?php echo number_format($money_expected,0,'',' '); ?> ₸</p>
    </div>
  </div>

  <h2>Скоро должны вернуть</h2>
  <ul>
  <?php foreach($soon as $o): ?>
    <li><a href="/?page=order_view&id=<?php echo $o['id']; ?>">Заказ №<?php echo $o['id']; ?></a> — <?php echo htmlspecialchars($o['client_name']); ?> — <?php echo $o['inventory_type']; ?> — <?php echo $o['date_end']; ?></li>
  <?php endforeach; ?>
  </ul>

  <h2>Просрочили</h2>
  <ul>
  <?php foreach($overdue as $o): ?>
    <li><a href="/?page=order_view&id=<?php echo $o['id']; ?>">Заказ №<?php echo $o['id']; ?></a> — <?php echo htmlspecialchars($o['client_name']); ?> — <?php echo $o['inventory_type']; ?> — <?php echo $o['date_end']; ?></li>
  <?php endforeach; ?>
  </ul>

  <h2>Чаще всего берут</h2>
  <ul>
  <?php foreach($popular as $p): ?>
    <li><?php echo htmlspecialchars($p['inventory_type']); ?> — <?php echo $p['cnt']; ?> раз</li>
  <?php endforeach; ?>
  </ul>
</div>
 
<div class="page-header"><h1>Графики</h1></div>
<div class="grid">
  <div class="card">
    <h3>М² выдано (за 7 дней)</h3>
    <div class="chart-wrapper"><canvas id="m2Chart"></canvas></div>
  </div>
  <div class="card">
    <h3>Сумма аренды (за 7 дней)</h3>
    <div class="chart-wrapper"><canvas id="moneyChart"></canvas></div>
  </div>
  <div class="card">
    <h3>Чаще всего берут</h3>
    <div class="chart-wrapper"><canvas id="popularChart"></canvas></div>
  </div>
  <div class="card">
    <h3>Статусы активных заказов</h3>
    <div class="chart-wrapper"><canvas id="statusChart"></canvas></div>
  </div>
</div>

<?php
// data for charts: last 7 days
$labels = [];
$m2data = [];
$moneydata = [];
for($i=6;$i>=0;$i--){
  $d = date('Y-m-d', strtotime("-{$i} days"));
  $labels[] = $d;
  $s = $db->prepare("SELECT SUM(m2) FROM orders WHERE date_start = :d"); $s->execute([':d'=>$d]); $m2data[] = (int)$s->fetchColumn();
  $s = $db->prepare("SELECT SUM(m2*days*price_per_m2) FROM orders WHERE date_start = :d"); $s->execute([':d'=>$d]); $moneydata[] = (int)$s->fetchColumn();
}
$popLabels = [];$popVals = [];
foreach($popular as $p){ $popLabels[] = $p['inventory_type']; $popVals[] = (int)$p['cnt']; }
$statusLabels = []; $statusVals = [];
foreach($statuses as $s){ $statusLabels[] = $s['status']; $statusVals[] = (int)$s['cnt']; }
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
window.__labels = <?php echo json_encode($labels); ?>;
window.__m2 = <?php echo json_encode($m2data); ?>;
window.__money = <?php echo json_encode($moneydata); ?>;
window.__popLabels = <?php echo json_encode($popLabels); ?>;
window.__popVals = <?php echo json_encode($popVals); ?>;
window.__statusLabels = <?php echo json_encode($statusLabels); ?>;
window.__statusVals = <?php echo json_encode($statusVals); ?>;
</script>
