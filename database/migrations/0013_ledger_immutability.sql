-- Phase 9: defence in depth for the append-only stock ledger and the audit trail.
-- Even with direct database access through the application account, ledger rows and
-- audit entries cannot be changed or removed. Corrections are new, opposite movements.
--
-- The only permitted UPDATE is moving stock_movements.created_at, used by the
-- development demo generator and the test-suite to build history. It requires the
-- session flag  SET @medistock_allow_backdate = 1  and may not touch any other column.
-- Each trigger is kept on one line: the migration runner splits statements on ";" at end of line.

DROP TRIGGER IF EXISTS trg_movements_no_delete;
CREATE TRIGGER trg_movements_no_delete BEFORE DELETE ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only: post a correcting movement instead';

DROP TRIGGER IF EXISTS trg_movements_no_update;
CREATE TRIGGER trg_movements_no_update BEFORE UPDATE ON stock_movements FOR EACH ROW IF COALESCE(@medistock_allow_backdate, 0) <> 1 OR NEW.id <> OLD.id OR NEW.transaction_id <> OLD.transaction_id OR NEW.product_id <> OLD.product_id OR NEW.batch_id <> OLD.batch_id OR NEW.movement_type <> OLD.movement_type OR NEW.quantity <> OLD.quantity OR NOT (NEW.from_location_id <=> OLD.from_location_id) OR NOT (NEW.to_location_id <=> OLD.to_location_id) OR NOT (NEW.unit_cost <=> OLD.unit_cost) OR NEW.performed_by <> OLD.performed_by THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only: post a correcting movement instead'; END IF;

DROP TRIGGER IF EXISTS trg_audit_no_update;
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

DROP TRIGGER IF EXISTS trg_audit_no_delete;
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
