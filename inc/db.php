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
    $db->exec("INSERT INTO inventory (type, total_m2) VALUES ('Стеновая', 1000), ('Перекрытие', 800), ('Стойка', 500);");
    
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
}

// Check definitions for SQLite vs Postgres
$intDef = $isPg ? 'INTEGER NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
$textDef = $isPg ? "TEXT NOT NULL DEFAULT 'Не оплачено'" : "TEXT NOT NULL DEFAULT 'Не оплачено'";
$tsDef = $isPg ? 'TIMESTAMP' : 'TEXT';

ensureColumn($db, $isPg, 'orders', 'returned_m2', $intDef);
ensureColumn($db, $isPg, 'orders', 'paid_amount', $intDef);
ensureColumn($db, $isPg, 'orders', 'payment_status', $textDef);
ensureColumn($db, $isPg, 'orders', 'updated_at', $tsDef);

// if no users exist, seed default admin
$cnt = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($cnt === 0) {
        $passwordHash = password_hash('admin', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (:u,:p,'admin')");
        $stmt->execute([':u'=>'admin',':p'=>$passwordHash]);
}

// start session for auth (if not already)
if (session_status() === PHP_SESSION_NONE) session_start();
?>
