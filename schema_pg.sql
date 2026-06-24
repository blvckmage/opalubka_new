-- schema for opalubka CRM (PostgreSQL)

CREATE TABLE IF NOT EXISTS clients (
  id SERIAL PRIMARY KEY,
  name TEXT NOT NULL,
  phone TEXT,
  client_type TEXT DEFAULT 'Физ.лицо'
);

CREATE TABLE IF NOT EXISTS inventory (
  id SERIAL PRIMARY KEY,
  type TEXT NOT NULL UNIQUE,
  total_m2 INTEGER NOT NULL DEFAULT 0,
  price INTEGER NOT NULL DEFAULT 0,
  unit TEXT NOT NULL DEFAULT 'ед.'
);

CREATE TABLE IF NOT EXISTS orders (
  id SERIAL PRIMARY KEY,
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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP,
  FOREIGN KEY(client_id) REFERENCES clients(id),
  FOREIGN KEY(referral_client_id) REFERENCES clients(id)
);

CREATE TABLE IF NOT EXISTS order_items (
  id SERIAL PRIMARY KEY,
  order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  inventory_type TEXT NOT NULL,
  m2 INTEGER NOT NULL,
  returned_m2 INTEGER DEFAULT 0,
  price_per_m2 INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS inventory_movements (
  id SERIAL PRIMARY KEY,
  inventory_type TEXT,
  delta_m2 INTEGER,
  reason TEXT,
  related_order_id INTEGER,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id SERIAL PRIMARY KEY,
  username TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  role TEXT DEFAULT 'admin'
);

CREATE TABLE IF NOT EXISTS order_files (
  id SERIAL PRIMARY KEY,
  order_id INTEGER NOT NULL,
  original_name TEXT NOT NULL,
  file_name TEXT NOT NULL,
  file_type TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id)
);

CREATE TABLE IF NOT EXISTS expense_categories (
  id SERIAL PRIMARY KEY,
  name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS expenses (
  id SERIAL PRIMARY KEY,
  category_id INTEGER NOT NULL,
  amount INTEGER NOT NULL DEFAULT 0,
  description TEXT,
  expense_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(category_id) REFERENCES expense_categories(id)
);
