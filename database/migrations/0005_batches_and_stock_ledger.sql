-- =====================================================================
-- 0005 Batches, the stock ledger (transactions + movements),
--      current balances, reservations, recalls
-- =====================================================================

-- A batch's available quantity is NOT stored here: it is SUM(stock_balances.quantity)
-- (see view v_batch_stock). batch_code is the system ID printed in batch QR codes.
CREATE TABLE product_batches (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id        BIGINT UNSIGNED NOT NULL,
    batch_code        VARCHAR(30) NOT NULL,
    lot_number        VARCHAR(60) NOT NULL,
    supplier_id       INT UNSIGNED NULL,
    manufacture_date  DATE NULL,
    expiry_date       DATE NULL,
    unit_cost         DECIMAL(14,4) NOT NULL DEFAULT 0,
    quantity_received DECIMAL(14,3) NOT NULL,
    received_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status            ENUM('active','quarantined','recalled','expired','depleted') NOT NULL DEFAULT 'active',
    notes             VARCHAR(255) NULL,
    created_by        BIGINT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_batches_code (batch_code),
    UNIQUE KEY uq_batches_lot (product_id, lot_number, supplier_id),
    KEY ix_batches_product_expiry (product_id, expiry_date),
    KEY ix_batches_expiry_status (expiry_date, status),
    CONSTRAINT fk_batches_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_batches_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
    CONSTRAINT fk_batches_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_batches_dates CHECK (expiry_date IS NULL OR manufacture_date IS NULL OR expiry_date > manufacture_date),
    CONSTRAINT ck_batches_qty CHECK (quantity_received > 0 AND unit_cost >= 0)
) ENGINE=InnoDB;

-- Header of one business stock operation (a receipt, an issue, a consumption ...).
CREATE TABLE stock_transactions (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    txn_number         VARCHAR(30) NOT NULL,
    type               ENUM('receipt','issue_dispatch','issue_receipt','transfer_dispatch','transfer_receipt',
                            'return','consumption','adjustment','write_off','expiry_disposal',
                            'recall_quarantine','count_correction','opening_balance','reversal') NOT NULL,
    reference_type     VARCHAR(40) NULL,
    reference_id       BIGINT UNSIGNED NULL,
    department_id      INT UNSIGNED NOT NULL,
    performed_by       BIGINT UNSIGNED NOT NULL,
    approved_by        BIGINT UNSIGNED NULL,
    approval_reference VARCHAR(60) NULL,
    reason             VARCHAR(255) NULL,
    notes              TEXT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_txn_number (txn_number),
    KEY ix_txn_reference (reference_type, reference_id),
    KEY ix_txn_department (department_id, created_at),
    KEY ix_txn_type (type, created_at),
    CONSTRAINT fk_txn_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_txn_performed_by FOREIGN KEY (performed_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_txn_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- THE LEDGER. Append-only. from NULL = entering hospital, to NULL = leaving hospital.
CREATE TABLE stock_movements (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id   BIGINT UNSIGNED NOT NULL,
    product_id       BIGINT UNSIGNED NOT NULL,
    batch_id         BIGINT UNSIGNED NOT NULL,
    movement_type    ENUM('RECEIVED','ISSUED','TRANSFERRED','RETURNED','CONSUMED','ADJUSTED_IN','ADJUSTED_OUT',
                          'DAMAGED','EXPIRED','WRITTEN_OFF','QUARANTINED') NOT NULL,
    from_location_id INT UNSIGNED NULL,
    to_location_id   INT UNSIGNED NULL,
    quantity         DECIMAL(14,3) NOT NULL,
    unit_cost        DECIMAL(14,4) NOT NULL DEFAULT 0,
    performed_by     BIGINT UNSIGNED NOT NULL,
    reason           VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_mov_transaction (transaction_id),
    KEY ix_mov_product (product_id, created_at),
    KEY ix_mov_batch (batch_id),
    KEY ix_mov_from (from_location_id, created_at),
    KEY ix_mov_to (to_location_id, created_at),
    KEY ix_mov_type (movement_type, created_at),
    KEY ix_mov_created (created_at),
    CONSTRAINT fk_mov_transaction FOREIGN KEY (transaction_id) REFERENCES stock_transactions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_from FOREIGN KEY (from_location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_to FOREIGN KEY (to_location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_user FOREIGN KEY (performed_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_mov_qty CHECK (quantity > 0),
    CONSTRAINT ck_mov_locations CHECK (from_location_id IS NOT NULL OR to_location_id IS NOT NULL),
    CONSTRAINT ck_mov_distinct CHECK (from_location_id IS NULL OR to_location_id IS NULL OR from_location_id <> to_location_id)
) ENGINE=InnoDB;

-- CURRENT STATE: a transactional cache of the ledger, reconciled nightly.
CREATE TABLE stock_balances (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    location_id      INT UNSIGNED NOT NULL,
    batch_id         BIGINT UNSIGNED NOT NULL,
    product_id       BIGINT UNSIGNED NOT NULL,
    quantity         DECIMAL(14,3) NOT NULL DEFAULT 0,
    last_movement_id BIGINT UNSIGNED NULL,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_balances_location_batch (location_id, batch_id),
    KEY ix_balances_product_location (product_id, location_id),
    KEY ix_balances_batch (batch_id),
    CONSTRAINT fk_bal_location FOREIGN KEY (location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bal_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bal_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bal_last_movement FOREIGN KEY (last_movement_id) REFERENCES stock_movements (id) ON DELETE SET NULL,
    CONSTRAINT ck_bal_quantity CHECK (quantity >= 0)
) ENGINE=InnoDB;

-- Emergency reservations: reserved stock is excluded from normal FEFO allocation.
CREATE TABLE stock_reservations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    location_id INT UNSIGNED NOT NULL,
    product_id  BIGINT UNSIGNED NOT NULL,
    batch_id    BIGINT UNSIGNED NULL,
    quantity    DECIMAL(14,3) NOT NULL,
    purpose     VARCHAR(255) NOT NULL,
    status      ENUM('pending','active','released','consumed','rejected') NOT NULL DEFAULT 'pending',
    reserved_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    valid_until DATETIME NULL,
    released_at DATETIME NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_res_location_product (location_id, product_id, status),
    CONSTRAINT fk_res_location FOREIGN KEY (location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_res_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_res_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_res_reserved_by FOREIGN KEY (reserved_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_res_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_res_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE batch_recalls (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recall_number VARCHAR(30) NOT NULL,
    batch_id      BIGINT UNSIGNED NOT NULL,
    reason        TEXT NOT NULL,
    severity      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'high',
    source        ENUM('manufacturer','regulator','supplier','internal') NOT NULL DEFAULT 'manufacturer',
    status        ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
    initiated_by  BIGINT UNSIGNED NOT NULL,
    closed_by     BIGINT UNSIGNED NULL,
    closed_at     DATETIME NULL,
    closure_notes TEXT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recall_number (recall_number),
    KEY ix_recalls_batch (batch_id),
    KEY ix_recalls_status (status),
    CONSTRAINT fk_recall_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recall_initiated_by FOREIGN KEY (initiated_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recall_closed_by FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
