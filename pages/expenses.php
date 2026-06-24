<?php
$error = '';
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_expense') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $amount = (int)($_POST['amount'] ?? 0);
        $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        
        if ($category_id <= 0 || $amount <= 0 || empty($expense_date)) {
            $error = 'Пожалуйста, заполните все обязательные поля корректно.';
        } else {
            $stmt = $db->prepare('INSERT INTO expenses (category_id, amount, description, expense_date) VALUES (:cid, :amount, :desc, :dt)');
            $stmt->execute([':cid' => $category_id, ':amount' => $amount, ':desc' => $description, ':dt' => $expense_date]);
            header('Location: /?page=expenses&success=1');
            exit;
        }
    } elseif ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = 'Имя категории не может быть пустым';
        } else {
            $stmt = $db->prepare('SELECT id FROM expense_categories WHERE lower(name) = lower(:name)');
            $stmt->execute([':name' => $name]);
            if ($stmt->fetchColumn()) {
                $error = 'Такая категория уже существует';
            } else {
                $stmt = $db->prepare('INSERT INTO expense_categories (name) VALUES (:name)');
                $stmt->execute([':name' => $name]);
                header('Location: /?page=expenses&cat_success=1');
                exit;
            }
        }
    }
}

if (!empty($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->prepare('DELETE FROM expenses WHERE id = :id')->execute([':id' => $id]);
    header('Location: /?page=expenses');
    exit;
}

if (!empty($_GET['success'])) $success = 'Расход успешно добавлен';
if (!empty($_GET['cat_success'])) $success = 'Категория добавлена';

$month = $_GET['month'] ?? date('Y-m');
$start_date = $month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

$categories = $db->query("SELECT * FROM expense_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch expenses for the selected month
$stmt = $db->prepare("
    SELECT e.*, c.name as category_name 
    FROM expenses e
    JOIN expense_categories c ON e.category_id = c.id
    WHERE e.expense_date >= :start AND e.expense_date <= :end
    ORDER BY e.expense_date DESC, e.id DESC
");
$stmt->execute([':start' => $start_date, ':end' => $end_date]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_expenses = array_sum(array_column($expenses, 'amount'));
?>
<div class="card" style="word-break: break-word;">
    <div class="page-header">
        <h1>Бухгалтерия (Расходы)</h1>
        <div class="actions">
            <button onclick="document.getElementById('catModal').classList.add('show')" class="badge" style="background:var(--line); color:var(--text); cursor:pointer;">Категории</button>
        </div>
    </div>
    
    <?php if($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if($success): ?><div class="notice"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <!-- Form to add expense -->
    <form method="post" class="form-grid compact-form" style="margin-bottom: 2rem; background: var(--bg); padding: 1rem; border-radius: var(--radius);">
        <input type="hidden" name="action" value="add_expense">
        <label>Категория
            <select name="category_id" required>
                <option value="">-- Выберите --</option>
                <?php foreach($categories as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Сумма (₸)
            <input type="number" name="amount" inputmode="numeric" min="1" required>
        </label>
        <label>Дата
            <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required>
        </label>
        <label class="full">Комментарий
            <input type="text" name="description" placeholder="Например: Покупка ГСМ для доставки">
        </label>
        <div class="full">
            <button type="submit" style="width: 100%;">Добавить расход</button>
        </div>
    </form>
    
    <!-- Filter and summary -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
        <form method="get" style="display: flex; gap: 0.5rem; align-items: center; width: 100%; max-width: 300px;">
            <input type="hidden" name="page" value="expenses">
            <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" onchange="this.form.submit()" style="flex: 1;">
        </form>
        <div class="metric-card" style="margin: 0; padding: 0.5rem 1rem; flex: 1; min-width: 150px;">
            <h3 style="margin: 0; font-size: 0.9rem;">Итого за месяц:</h3>
            <p class="big" style="margin: 0; color: var(--danger);"><?php echo number_format($total_expenses, 0, '', ' '); ?> ₸</p>
        </div>
    </div>

    <!-- Expenses Table -->
    <div style="overflow:auto">
        <table>
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Категория</th>
                    <th>Сумма</th>
                    <th>Комментарий</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($expenses)): ?>
                <tr><td colspan="5" style="text-align:center; padding: 2rem;">Нет расходов за выбранный месяц</td></tr>
                <?php endif; ?>
                <?php foreach($expenses as $e): ?>
                <tr>
                    <td data-label="Дата"><?php echo htmlspecialchars($e['expense_date']); ?></td>
                    <td data-label="Категория"><span class="status-pill"><?php echo htmlspecialchars($e['category_name']); ?></span></td>
                    <td data-label="Сумма" class="danger-text"><strong><?php echo number_format($e['amount'], 0, '', ' '); ?> ₸</strong></td>
                    <td data-label="Комментарий"><?php echo htmlspecialchars($e['description'] ?? ''); ?></td>
                    <td data-label="Действия">
                        <a href="/?page=expenses&delete=<?php echo $e['id']; ?>" class="danger-text mini-link" onclick="return confirm('Удалить этот расход?')">Удалить</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Category Modal -->
<div id="catModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">Новая категория</div>
    <form method="post" class="form-grid compact-form">
      <input type="hidden" name="action" value="add_category">
      <label class="full">Название категории
        <input name="name" required placeholder="Например: Инструменты">
      </label>
      <div class="full" style="display: flex; gap: 1rem; margin-top: 1rem;">
        <button type="submit" style="flex: 1;">Добавить</button>
        <button type="button" class="modal_cancel" style="flex: 1; background: var(--border); color: var(--text);">Отмена</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const catModal = document.getElementById('catModal');
  const cancelBtns = document.querySelectorAll('.modal_cancel');
  
  function closeModals() {
    catModal.classList.remove('show');
  }
  
  cancelBtns.forEach(btn => btn.addEventListener('click', closeModals));
  catModal.addEventListener('click', function(e) {
    if (e.target === catModal) closeModals();
  });
});
</script>
