-- Kedai Aina: a small Malaysian home appliance shop, built to test database
-- answers against facts that are known exactly. Every expected answer in
-- api-engine/evals/sets/kedai-aina.json follows from the rows below.

PRAGMA foreign_keys = ON;

DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS customers;

CREATE TABLE customers (
    id INTEGER PRIMARY KEY,
    full_name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    phone TEXT,
    state TEXT NOT NULL,
    joined_on TEXT NOT NULL
);

CREATE TABLE products (
    id INTEGER PRIMARY KEY,
    sku TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    price_rm REAL NOT NULL,
    stock_qty INTEGER NOT NULL,
    warranty_months INTEGER NOT NULL
);

CREATE TABLE orders (
    id INTEGER PRIMARY KEY,
    order_no TEXT NOT NULL UNIQUE,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    order_date TEXT NOT NULL,
    status TEXT NOT NULL,
    shipping_state TEXT NOT NULL,
    total_rm REAL NOT NULL
);

CREATE TABLE order_items (
    id INTEGER PRIMARY KEY,
    order_id INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL REFERENCES products(id),
    quantity INTEGER NOT NULL,
    unit_price_rm REAL NOT NULL
);

INSERT INTO customers (id, full_name, email, phone, state, joined_on) VALUES
    (1, 'Aina Rahman', 'aina.rahman@example.my', '+60 12-345 6789', 'Selangor', '2025-11-02'),
    (2, 'Tan Wei Ming', 'weiming.tan@example.my', '+60 16-222 3344', 'Johor', '2026-01-15'),
    (3, 'Siti Nurhaliza Omar', 'siti.omar@example.my', '+60 13-555 7788', 'Kuala Lumpur', '2026-02-20'),
    (4, 'Rajesh Kumar', 'rajesh.kumar@example.my', '+60 17-888 1122', 'Penang', '2026-03-08'),
    (5, 'Nurul Izzah Hamid', 'nurul.hamid@example.my', '+60 19-444 5566', 'Sabah', '2026-04-11'),
    (6, 'Lim Mei Ling', 'meiling.lim@example.my', '+60 12-777 9900', 'Johor', '2026-05-23'),
    (7, 'Ahmad Faiz Ismail', 'faiz.ismail@example.my', '+60 11-3333 4455', 'Perak', '2026-06-30'),
    (8, 'Chong Kah Wai', 'kahwai.chong@example.my', '+60 14-666 2233', 'Sarawak', '2026-07-14');

INSERT INTO products (id, sku, name, category, price_rm, stock_qty, warranty_months) VALUES
    (1, 'AF-X200', 'X200 Air Fryer', 'Kitchen', 399.00, 42, 24),
    (2, 'AF-X100', 'X100 Compact Air Fryer', 'Kitchen', 259.00, 0, 24),
    (3, 'KT-K5', 'K5 Electric Kettle', 'Kitchen', 89.00, 120, 12),
    (4, 'RC-R3', 'R3 Rice Cooker', 'Kitchen', 179.00, 35, 12),
    (5, 'BL-B7', 'B7 Blender', 'Kitchen', 229.00, 18, 12),
    (6, 'MW-M8', 'M8 Microwave Oven', 'Kitchen', 459.00, 9, 24),
    (7, 'VC-V9', 'V9 Cordless Vacuum', 'Cleaning', 899.00, 14, 24),
    (8, 'IR-I4', 'I4 Steam Iron', 'Laundry', 119.00, 60, 12),
    (9, 'FN-F2', 'F2 Stand Fan', 'Cooling', 149.00, 75, 12),
    (10, 'AP-A6', 'A6 Air Purifier', 'Cooling', 649.00, 6, 36);

INSERT INTO orders (id, order_no, customer_id, order_date, status, shipping_state, total_rm) VALUES
    (1, 'KA-1001', 1, '2026-08-03', 'delivered', 'Selangor', 399.00),
    (2, 'KA-1002', 2, '2026-08-05', 'delivered', 'Johor', 357.00),
    (3, 'KA-1003', 3, '2026-08-12', 'shipped', 'Kuala Lumpur', 899.00),
    (4, 'KA-1004', 4, '2026-08-18', 'cancelled', 'Penang', 459.00),
    (5, 'KA-1005', 5, '2026-08-21', 'delivered', 'Sabah', 298.00),
    (6, 'KA-1006', 1, '2026-08-28', 'refunded', 'Selangor', 229.00),
    (7, 'KA-1007', 6, '2026-09-01', 'shipped', 'Johor', 518.00),
    (8, 'KA-1008', 7, '2026-09-04', 'paid', 'Perak', 649.00),
    (9, 'KA-1009', 8, '2026-09-06', 'pending', 'Sarawak', 358.00),
    (10, 'KA-1010', 2, '2026-09-08', 'shipped', 'Johor', 798.00),
    (11, 'KA-1011', 3, '2026-09-10', 'paid', 'Kuala Lumpur', 208.00),
    (12, 'KA-1012', 1, '2026-09-12', 'pending', 'Selangor', 649.00);

INSERT INTO order_items (id, order_id, product_id, quantity, unit_price_rm) VALUES
    (1, 1, 1, 1, 399.00),
    (2, 2, 3, 2, 89.00),
    (3, 2, 4, 1, 179.00),
    (4, 3, 7, 1, 899.00),
    (5, 4, 6, 1, 459.00),
    (6, 5, 9, 2, 149.00),
    (7, 6, 5, 1, 229.00),
    (8, 7, 1, 1, 399.00),
    (9, 7, 8, 1, 119.00),
    (10, 8, 10, 1, 649.00),
    (11, 9, 4, 2, 179.00),
    (12, 10, 1, 2, 399.00),
    (13, 11, 3, 1, 89.00),
    (14, 11, 8, 1, 119.00),
    (15, 12, 10, 1, 649.00);
