-- =====================================================================
-- 0006 Goods receipts (GRN). Posting a GRN creates batches + RECEIVED movements.
-- =====================================================================

CREATE TABLE goods_receipts (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    grn_number            VARCHAR(30) NOT NULL,
    purchase_order_id     BIGINT UNSIGNED NULL,
    supplier_id           INT UNSIGNED NULL,
    receipt_type          ENUM('purchase_order','direct','donation','opening_balance') NOT NULL DEFAULT 'purchase_order',
    receiving_location_id INT UNSIGNED NOT NULL,
    delivery_note_number  VARCHAR(60) NULL,
    invoice_number        VARCHAR(60) NULL,
    status                ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
    submission_token      CHAR(32) NOT NULL,
    received_by           BIGINT UNSIGNED NOT NULL,
    received_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    posted_at             DATETIME NULL,
    notes                 TEXT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grn_number (grn_number),
    UNIQUE KEY uq_grn_token (submission_token),
    UNIQUE KEY uq_grn_delivery_note (supplier_id, delivery_note_number),
    KEY ix_grn_po (purchase_order_id),
    CONSTRAINT fk_grn_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE RESTRICT,
    CONSTRAINT fk_grn_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_grn_location FOREIGN KEY (receiving_location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_grn_received_by FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE goods_receipt_items (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    goods_receipt_id       BIGINT UNSIGNED NOT NULL,
    purchase_order_item_id BIGINT UNSIGNED NULL,
    product_id             BIGINT UNSIGNED NOT NULL,
    lot_number             VARCHAR(60) NOT NULL,
    manufacture_date       DATE NULL,
    expiry_date            DATE NULL,
    qty_received           DECIMAL(14,3) NOT NULL,
    qty_rejected           DECIMAL(14,3) NOT NULL DEFAULT 0,
    rejection_reason       VARCHAR(255) NULL,
    unit_cost              DECIMAL(14,4) NOT NULL DEFAULT 0,
    batch_id               BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY ix_grni_grn (goods_receipt_id),
    KEY ix_grni_po_item (purchase_order_item_id),
    KEY ix_grni_batch (batch_id),
    CONSTRAINT fk_grni_grn FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts (id) ON DELETE CASCADE,
    CONSTRAINT fk_grni_po_item FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items (id) ON DELETE RESTRICT,
    CONSTRAINT fk_grni_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_grni_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT,
    CONSTRAINT ck_grni_qty CHECK (qty_received >= 0 AND qty_rejected >= 0 AND (qty_received + qty_rejected) > 0)
) ENGINE=InnoDB;
