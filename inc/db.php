<?php
// PostgreSQL & SQLite connection and initialization
$dbUrl = getenv('DATABASE_URL');
$isPg = false;

if ($dbUrl) {
    // e.g. postgres://user:pass@host:port/dbname
    $parsedUrl = parse_url($dbUrl);
    $host = $parsedUrl['host'] ?? '';
    $port = $parsedUrl['port'] ?? 5432;
    $user = $parsedUrl['user'] ?? '';
    $pass = $parsedUrl['pass'] ?? '';
    $dbName = ltrim($parsedUrl['path'] ?? '', '/');
    
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";
    $db = new PDO($dsn, $user, $pass);
    $isPg = true;
    
    // Check if init is needed for Postgres by checking if clients table exists
    $stmt = $db->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'clients'");
    $init = !$stmt->fetch();
} else {
    // Fallback to SQLite
    $dbFile = __DIR__ . '/../data/opalubka.sqlite';
    if (!file_exists(dirname($dbFile))) {
        mkdir(dirname($dbFile), 0755, true);
    }
    $init = !file_exists($dbFile);
    $db = new PDO('sqlite:' . $dbFile);
}

$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($isPg) {
    // Включаем эмуляцию prepared statements, так как Supabase IPv4 Pooler (PgBouncer)
    // по умолчанию работает в transaction mode и не поддерживает нативные prepared statements
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
}

function ensureColumn(PDO $db, bool $isPg, string $table, string $column, string $definition): void {
    if ($isPg) {
        $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = :table AND column_name = :col");
        $stmt->execute([':table' => $table, ':col' => $column]);
        if ($stmt->fetch()) return;
    } else {
        $cols = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            if ($col['name'] === $column) return;
        }
    }
    $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

if ($init) {
    $schemaFile = $isPg ? __DIR__ . '/../schema_pg.sql' : __DIR__ . '/../schema.sql';
    $sql = file_get_contents($schemaFile);
    $db->exec($sql);
    
    // seed sample data
    $db->exec("INSERT INTO clients (name, phone) VALUES ('ООО Ромашка', '+7 701 000 0000'), ('ИП Иванов', '+7 701 111 1111');");
    $db->exec("INSERT INTO inventory (type, total_m2, price, unit) VALUES ('Стеновые опалубки', 1000, 340, 'м²'), ('Колонные опалубки', 1000, 3000, 'компл.'), ('Ригель', 1000, 500, 'м'), ('Струбцины', 1000, 100, 'шт');");
    
    // seed admin user (password: admin)
    $passwordHash = password_hash('admin', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (:u,:p,'admin')");
    $stmt->execute([':u'=>'admin',':p'=>$passwordHash]);
}

// ensure `users` table exists
if ($isPg) {
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT DEFAULT 'admin'
    );
SQL
    );
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS order_items (
        id SERIAL PRIMARY KEY,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        inventory_type TEXT NOT NULL,
        m2 INTEGER NOT NULL,
        returned_m2 INTEGER DEFAULT 0,
        price_per_m2 INTEGER NOT NULL
    );
SQL
    );
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS order_files (
        id SERIAL PRIMARY KEY,
        order_id INTEGER NOT NULL,
        original_name TEXT NOT NULL,
        file_name TEXT NOT NULL,
        file_type TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(order_id) REFERENCES orders(id)
    );
SQL
    );
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS expense_categories (
        id SERIAL PRIMARY KEY,
        name TEXT NOT NULL UNIQUE
    );
SQL
    );
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS expenses (
        id SERIAL PRIMARY KEY,
        category_id INTEGER NOT NULL,
        amount INTEGER NOT NULL DEFAULT 0,
        description TEXT,
        expense_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(category_id) REFERENCES expense_categories(id)
    );
SQL
    );
} else {
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT DEFAULT 'admin'
    );
SQL
    );
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        inventory_type TEXT NOT NULL,
        m2 INTEGER NOT NULL,
        returned_m2 INTEGER DEFAULT 0,
        price_per_m2 INTEGER NOT NULL,
        FOREIGN KEY(order_id) REFERENCES orders(id)
    );
SQL
    );
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS order_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        original_name TEXT NOT NULL,
        file_name TEXT NOT NULL,
        file_type TEXT,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY(order_id) REFERENCES orders(id)
    );
SQL
    );
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS expense_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    );
SQL
    );
    $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        amount INTEGER NOT NULL DEFAULT 0,
        description TEXT,
        expense_date TEXT NOT NULL,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY(category_id) REFERENCES expense_categories(id)
    );
SQL
    );
}

// Check definitions for SQLite vs Postgres
$intDef = $isPg ? 'INTEGER NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
$textDef = $isPg ? "TEXT NOT NULL DEFAULT 'Не оплачено'" : "TEXT NOT NULL DEFAULT 'Не оплачено'";
$tsDef = $isPg ? 'TIMESTAMP' : 'TEXT';

ensureColumn($db, $isPg, 'orders', 'returned_m2', $intDef);
ensureColumn($db, $isPg, 'orders', 'paid_amount', $intDef);
ensureColumn($db, $isPg, 'orders', 'payment_status', $textDef);
ensureColumn($db, $isPg, 'orders', 'updated_at', $tsDef);
ensureColumn($db, $isPg, 'orders', 'discount_amount', $intDef);
ensureColumn($db, $isPg, 'orders', 'referral_client_id', 'INTEGER');
ensureColumn($db, $isPg, 'clients', 'client_type', "TEXT DEFAULT 'Физ.лицо'");
ensureColumn($db, $isPg, 'orders', 'delivery_fee', $intDef);
ensureColumn($db, $isPg, 'orders', 'tax_percentage', $intDef);
ensureColumn($db, $isPg, 'orders', 'discount_percentage', $intDef);
ensureColumn($db, $isPg, 'inventory', 'price', $intDef);
ensureColumn($db, $isPg, 'inventory', 'unit', "TEXT NOT NULL DEFAULT 'ед.'");

// Cleanup old items
$db->exec("DELETE FROM inventory WHERE type IN ('Перекрытие', 'Стеновая', 'Стойка')");

// if no users exist, seed default admin
$cnt = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($cnt === 0) {
        $passwordHash = password_hash('admin', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (:u,:p,'admin')");
        $stmt->execute([':u'=>'admin',':p'=>$passwordHash]);
}

// ensure required inventory types exist
$required_items = [
    'Стеновые опалубки' => ['price'=>340, 'unit'=>'м²'],
    'Колонные опалубки' => ['price'=>3000, 'unit'=>'компл.'],
    'Ригель' => ['price'=>500, 'unit'=>'м'],
    'Струбцины' => ['price'=>100, 'unit'=>'шт']
];
foreach($required_items as $ri => $props) {
    $stmt = $db->prepare("SELECT id FROM inventory WHERE type = :type");
    $stmt->execute([':type' => $ri]);
    if (!$stmt->fetchColumn()) {
        $db->prepare("INSERT INTO inventory (type, total_m2, price, unit) VALUES (:type, 1000, :p, :u)")->execute([':type' => $ri, ':p' => $props['price'], ':u' => $props['unit']]);
    }
}

// seed default expense categories
$default_cats = ['Зарплата', 'Транспорт/ГСМ', 'Ремонт опалубки', 'Аренда базы', 'Налоги', 'Офис', 'Прочее'];
foreach($default_cats as $cat) {
    $stmt = $db->prepare("SELECT id FROM expense_categories WHERE name = :name");
    $stmt->execute([':name' => $cat]);
    if (!$stmt->fetchColumn()) {
        $db->prepare("INSERT INTO expense_categories (name) VALUES (:name)")->execute([':name' => $cat]);
    }
}

// Migrate orders to order_items if needed
try {
    $cnt_items = (int)$db->query("SELECT COUNT(*) FROM order_items")->fetchColumn();
    if ($cnt_items === 0) {
        $old_orders = $db->query("SELECT id, inventory_type, m2, returned_m2, price_per_m2 FROM orders WHERE inventory_type IS NOT NULL AND inventory_type != ''")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($old_orders)) {
            $stmt = $db->prepare("INSERT INTO order_items (order_id, inventory_type, m2, returned_m2, price_per_m2) VALUES (:oid, :type, :m2, :ret, :price)");
            foreach($old_orders as $o) {
                $stmt->execute([
                    ':oid' => $o['id'],
                    ':type' => $o['inventory_type'],
                    ':m2' => (int)$o['m2'],
                    ':ret' => (int)$o['returned_m2'],
                    ':price' => (int)$o['price_per_m2']
                ]);
            }
        }
    }
} catch (Exception $e) {}

// start session for auth (if not already)
if (session_status() === PHP_SESSION_NONE) session_start();

// Migrate orders to order_items if needed
try {
    $cnt_items = (int)$db->query("SELECT COUNT(*) FROM order_items")->fetchColumn();
    if ($cnt_items === 0) {
        $old_orders = $db->query("SELECT id, inventory_type, m2, returned_m2, price_per_m2 FROM orders WHERE inventory_type IS NOT NULL AND inventory_type != ''")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($old_orders)) {
            $stmt = $db->prepare("INSERT INTO order_items (order_id, inventory_type, m2, returned_m2, price_per_m2) VALUES (:oid, :type, :m2, :ret, :price)");
            foreach($old_orders as $o) {
                $stmt->execute([
                    ':oid' => $o['id'],
                    ':type' => $o['inventory_type'],
                    ':m2' => (int)$o['m2'],
                    ':ret' => (int)$o['returned_m2'],
                    ':price' => (int)$o['price_per_m2']
                ]);
            }
        }
    }
} catch (Exception $e) {}
?>
