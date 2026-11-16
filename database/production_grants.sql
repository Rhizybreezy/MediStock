-- =====================================================================
-- MediStock Intelligence - least-privilege database accounts (production)
-- =====================================================================
-- Run once as a MySQL/MariaDB administrator AFTER `php bin/console migrate`:
--     mysql -u root -p < database/production_grants.sql
-- Replace both CHANGE_ME passwords first (use long random values) and keep
-- 'localhost' if the database runs on the same server as the web server.
--
-- 1. medistock_app      - used by the web application and cron (.env DB_USERNAME).
--                         Read/write on business tables, but the stock ledger
--                         (stock_movements) and the audit trail (audit_logs) are
--                         SELECT + INSERT only: rows can never be changed or removed,
--                         on top of the append-only triggers from migration 0013.
--                         No DDL, no DROP, no GRANT, no FILE.
-- 2. medistock_migrate  - used only by an administrator for `migrate` / `db:seed`
--                         during deployments (pass it via a temporary .env or env vars).
--
-- If you add tables in a later migration, add matching GRANT lines here.
-- Regenerate this list with:
--   SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'medistock';
-- =====================================================================

CREATE USER IF NOT EXISTS 'medistock_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_app_password';
CREATE USER IF NOT EXISTS 'medistock_migrate'@'localhost' IDENTIFIED BY 'CHANGE_ME_migrate_password';

-- Migration account: full control of this schema only (needs TRIGGER for migration 0013).
GRANT ALL PRIVILEGES ON `medistock`.* TO 'medistock_migrate'@'localhost';

-- Application account: append-only ledger and audit trail.
GRANT SELECT, INSERT ON `medistock`.`stock_movements` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT ON `medistock`.`audit_logs` TO 'medistock_app'@'localhost';

-- Application account: migration history is read-only (system:check reads it).
GRANT SELECT ON `medistock`.`migrations` TO 'medistock_app'@'localhost';

-- Application account: business tables.
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`ai_conversations` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`ai_messages` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`batch_recalls` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`departments` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`department_product_settings` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`document_sequences` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`equipment` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`equipment_maintenance` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`goods_receipts` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`goods_receipt_items` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`inventory_categories` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`inventory_locations` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`login_attempts` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`notifications` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`permissions` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`products` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`product_batches` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`product_units` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`purchase_orders` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`purchase_order_items` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`purchase_requests` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`purchase_request_items` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`roles` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`role_permissions` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_adjustments` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_adjustment_items` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_balances` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_counts` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_count_items` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_requests` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_request_items` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_reservations` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_transactions` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_transfers` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`stock_transfer_items` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`suppliers` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`supplier_products` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`system_settings` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`temperature_logs` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`units_of_measure` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`users` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`user_departments` TO 'medistock_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `medistock`.`user_roles` TO 'medistock_app'@'localhost';

-- Application account: reporting views (read-only).
GRANT SELECT ON `medistock`.`v_batch_stock` TO 'medistock_app'@'localhost';
GRANT SELECT ON `medistock`.`v_daily_consumption` TO 'medistock_app'@'localhost';
GRANT SELECT ON `medistock`.`v_department_stock` TO 'medistock_app'@'localhost';
GRANT SELECT ON `medistock`.`v_product_stock` TO 'medistock_app'@'localhost';

FLUSH PRIVILEGES;
