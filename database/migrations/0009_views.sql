-- =====================================================================
-- 0009 Read-only reporting views
-- "usable" stock = active batch, not expired, not in quarantine/transit.
-- =====================================================================

CREATE OR REPLACE VIEW v_batch_stock AS
SELECT b.id                AS batch_id,
       b.product_id,
       b.batch_code,
       b.lot_number,
       b.supplier_id,
       b.expiry_date,
       b.status,
       b.unit_cost,
       DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry,
       COALESCE(SUM(sb.quantity), 0)       AS on_hand,
       COALESCE(SUM(sb.quantity), 0) * b.unit_cost AS value
FROM product_batches b
LEFT JOIN stock_balances sb ON sb.batch_id = b.id
GROUP BY b.id;

CREATE OR REPLACE VIEW v_department_stock AS
SELECT l.department_id,
       sb.product_id,
       SUM(sb.quantity) AS on_hand,
       SUM(CASE WHEN b.status = 'active'
                 AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                 AND l.type NOT IN ('quarantine','transit')
                THEN sb.quantity ELSE 0 END) AS usable_qty,
       SUM(sb.quantity * b.unit_cost) AS value
FROM stock_balances sb
JOIN product_batches b      ON b.id = sb.batch_id
JOIN inventory_locations l  ON l.id = sb.location_id
WHERE sb.quantity > 0
GROUP BY l.department_id, sb.product_id;

CREATE OR REPLACE VIEW v_product_stock AS
SELECT p.id AS product_id,
       p.product_code,
       p.name,
       p.category_id,
       p.reorder_level,
       p.min_level,
       p.max_level,
       p.is_active,
       COALESCE(SUM(sb.quantity), 0) AS on_hand,
       COALESCE(SUM(CASE WHEN b.status = 'active'
                          AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                          AND l.type <> 'quarantine'
                         THEN sb.quantity ELSE 0 END), 0) AS usable_qty,
       COALESCE(SUM(sb.quantity * b.unit_cost), 0) AS value
FROM products p
LEFT JOIN stock_balances sb      ON sb.product_id = p.id AND sb.quantity > 0
LEFT JOIN product_batches b      ON b.id = sb.batch_id
LEFT JOIN inventory_locations l  ON l.id = sb.location_id
GROUP BY p.id;

-- Daily consumption per product per department (zero-days filled in PHP)
CREATE OR REPLACE VIEW v_daily_consumption AS
SELECT m.product_id,
       l.department_id,
       DATE(m.created_at) AS day,
       SUM(m.quantity)    AS qty,
       SUM(m.quantity * m.unit_cost) AS value
FROM stock_movements m
JOIN inventory_locations l ON l.id = m.from_location_id
WHERE m.movement_type = 'CONSUMED'
GROUP BY m.product_id, l.department_id, DATE(m.created_at);
