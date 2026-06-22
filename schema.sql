-- schema for opalubka CRM

CREATE TABLE clients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  phone TEXT,
  client_type TEXT DEFAULT 'Физ.лицо'
);

CREATE TABLE IF NOT EXISTS inventory (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL UNIQUE,
  total_m2 INTEGER NOT NULL DEFAULT 0,
  price INTEGER NOT NULL DEFAULT 0,
  unit TEXT NOT NULL DEFAULT 'ед.'
);

CREATE TABLE orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL,
  client_name TEXT NOT NULL,
  client_phone TEXT,
  address TEXT,
  inventory_type TEXT,
  m2 INTEGER,
  days INTEGER,
  price_per_m2 INTEGER,
  delivery_fee INTEGER NOT NULL DEFAULT 0,
  tax_percentage INTEGER NOT NULL DEFAULT 0,
  discount_amount INTEGER NOT NULL DEFAULT 0,
  discount_percentage INTEGER NOT NULL DEFAULT 0,
  referral_client_id INTEGER,
  date_start TEXT,
  date_end TEXT,
  deposit INTEGER,
  returned_m2 INTEGER NOT NULL DEFAULT 0,
  paid_amount INTEGER NOT NULL DEFAULT 0,
  payment_status TEXT NOT NULL DEFAULT 'Не оплачено',
  comment TEXT,
  status TEXT DEFAULT 'В аренде',
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT,
  FOREIGN KEY(client_id) REFERENCES clients(id),
  FOREIGN KEY(referral_client_id) REFERENCES clients(id)
);

CREATE TABLE inventory_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  inventory_type TEXT,
  delta_m2 INTEGER,
  reason TEXT,
  related_order_id INTEGER,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  role TEXT DEFAULT 'admin'
);

CREATE TABLE order_files (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  original_name TEXT NOT NULL,
  file_name TEXT NOT NULL,
  file_type TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY(order_id) REFERENCES orders(id)
);

CREATE TABLE IF NOT EXISTS expense_categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS expenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id INTEGER NOT NULL,
  amount INTEGER NOT NULL DEFAULT 0,
  description TEXT,
  expense_date TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY(category_id) REFERENCES expense_categories(id)
);
