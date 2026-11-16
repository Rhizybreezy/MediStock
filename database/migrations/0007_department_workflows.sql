-- =====================================================================
-- 0007 Department workflows: requests, transfers/returns, adjustments, counts
-- =====================================================================

CREATE TABLE stock_requests (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_number           VARCHAR(30) NOT NULL,
    requesting_department_id INT UNSIGNED NOT NULL,
    supplying_department_id  INT UNSIGNED NOT NULL,
    destination_location_id  INT UNSIGNED NOT NULL,
    priority                 ENUM('routine','urgent','emergency') NOT NULL DEFAULT 'routine',
    status                   ENUM('draft','submitted','approved','partially_approved','rejected',
                                  'partially_issued','issued','received','cancelled') NOT NULL DEFAULT 'draft',
    needed_by                DATE NULL,
    notes                    TEXT NULL,
    requested_by             BIGINT UNSIGNED NOT NULL,
    submitted_at             DATETIME NULL,
    reviewed_by              BIGINT UNSIGNED NULL,
    reviewed_at              DATETIME NULL,
    review_notes             VARCHAR(255) NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_request_number (request_number),
    KEY ix_requests_status (status, requesting_department_id),
    CONSTRAINT fk_req_requesting FOREIGN KEY (requesting_department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_req_supplying FOREIGN KEY (supplying_department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_req_location FOREIGN KEY (destination_location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_req_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_req_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_req_departments CHECK (requesting_department_id <> supplying_department_id)
) ENGINE=InnoDB;

CREATE TABLE stock_request_items (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stock_request_id BIGINT UNSIGNED NOT NULL,
    product_id       BIGINT UNSIGNED NOT NULL,
    requested_qty    DECIMAL(14,3) NOT NULL,
    approved_qty     DECIMAL(14,3) NULL,
    issued_qty       DECIMAL(14,3) NOT NULL DEFAULT 0,
    received_qty     DECIMAL(14,3) NOT NULL DEFAULT 0,
    notes            VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_request_items (stock_request_id, product_id),
    KEY ix_request_items_product (product_id),
    CONSTRAINT fk_sri_request FOREIGN KEY (stock_request_id) REFERENCES stock_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_sri_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT ck_sri_qty CHECK (requested_qty > 0 AND (approved_qty IS NULL OR approved_qty >= 0)
                                 AND issued_qty >= 0 AND received_qty >= 0 AND received_qty <= issued_qty)
) ENGINE=InnoDB;

CREATE TABLE stock_transfers (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transfer_number           VARCHAR(30) NOT NULL,
    type                      ENUM('transfer','return_to_store','redistribution') NOT NULL DEFAULT 'transfer',
    source                    ENUM('manual','ai_proposal','redistribution_engine') NOT NULL DEFAULT 'manual',
    source_department_id      INT UNSIGNED NOT NULL,
    source_location_id        INT UNSIGNED NOT NULL,
    destination_department_id INT UNSIGNED NOT NULL,
    destination_location_id   INT UNSIGNED NOT NULL,
    status                    ENUM('draft','pending_approval','approved','rejected','dispatched','received','cancelled') NOT NULL DEFAULT 'draft',
    reason                    VARCHAR(255) NULL,
    requested_by              BIGINT UNSIGNED NOT NULL,
    approved_by               BIGINT UNSIGNED NULL,
    approved_at               DATETIME NULL,
    dispatched_by             BIGINT UNSIGNED NULL,
    dispatched_at             DATETIME NULL,
    received_by               BIGINT UNSIGNED NULL,
    received_at               DATETIME NULL,
    rejection_reason          VARCHAR(255) NULL,
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transfer_number (transfer_number),
    KEY ix_transfers_status (status),
    KEY ix_transfers_source (source_department_id, status),
    KEY ix_transfers_dest (destination_department_id, status),
    CONSTRAINT fk_trf_src_dept FOREIGN KEY (source_department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_trf_src_loc FOREIGN KEY (source_location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_trf_dst_dept FOREIGN KEY (destination_department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_trf_dst_loc FOREIGN KEY (destination_location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_trf_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_trf_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_trf_dispatched_by FOREIGN KEY (dispatched_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_trf_received_by FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_trf_locations CHECK (source_location_id <> destination_location_id)
) ENGINE=InnoDB;

CREATE TABLE stock_transfer_items (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stock_transfer_id BIGINT UNSIGNED NOT NULL,
    product_id        BIGINT UNSIGNED NOT NULL,
    batch_id          BIGINT UNSIGNED NULL,
    requested_qty     DECIMAL(14,3) NOT NULL,
    approved_qty      DECIMAL(14,3) NULL,
    dispatched_qty    DECIMAL(14,3) NOT NULL DEFAULT 0,
    received_qty      DECIMAL(14,3) NOT NULL DEFAULT 0,
    notes             VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY ix_sti_transfer (stock_transfer_id),
    KEY ix_sti_product (product_id),
    KEY ix_sti_batch (batch_id),
    CONSTRAINT fk_sti_transfer FOREIGN KEY (stock_transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE,
    CONSTRAINT fk_sti_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sti_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT,
    CONSTRAINT ck_sti_qty CHECK (requested_qty > 0 AND dispatched_qty >= 0 AND received_qty >= 0 AND received_qty <= dispatched_qty)
) ENGINE=InnoDB;

CREATE TABLE stock_counts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    count_number VARCHAR(30) NOT NULL,
    location_id  INT UNSIGNED NOT NULL,
    type         ENUM('full','cycle','spot') NOT NULL DEFAULT 'cycle',
    status       ENUM('in_progress','submitted','approved','cancelled') NOT NULL DEFAULT 'in_progress',
    notes        VARCHAR(255) NULL,
    started_by   BIGINT UNSIGNED NOT NULL,
    submitted_at DATETIME NULL,
    approved_by  BIGINT UNSIGNED NULL,
    approved_at  DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_count_number (count_number),
    KEY ix_counts_location (location_id, status),
    CONSTRAINT fk_count_location FOREIGN KEY (location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_count_started_by FOREIGN KEY (started_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_count_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE stock_count_items (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stock_count_id BIGINT UNSIGNED NOT NULL,
    batch_id       BIGINT UNSIGNED NOT NULL,
    system_qty     DECIMAL(14,3) NOT NULL,
    counted_qty    DECIMAL(14,3) NULL,
    variance       DECIMAL(14,3) AS (counted_qty - system_qty) STORED,
    counted_by     BIGINT UNSIGNED NULL,
    counted_at     DATETIME NULL,
    notes          VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_count_items (stock_count_id, batch_id),
    CONSTRAINT fk_sci_count FOREIGN KEY (stock_count_id) REFERENCES stock_counts (id) ON DELETE CASCADE,
    CONSTRAINT fk_sci_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sci_counted_by FOREIGN KEY (counted_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_sci_qty CHECK (system_qty >= 0 AND (counted_qty IS NULL OR counted_qty >= 0))
) ENGINE=InnoDB;

-- Adjustments (damage, loss, expiry write-off, count variance, correction). Always approval-gated.
CREATE TABLE stock_adjustments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    adjustment_number VARCHAR(30) NOT NULL,
    location_id       INT UNSIGNED NOT NULL,
    reason_code       ENUM('damaged','expired','lost','theft_suspected','count_variance','data_correction','found','write_off') NOT NULL,
    status            ENUM('pending','approved','rejected','posted') NOT NULL DEFAULT 'pending',
    stock_count_id    BIGINT UNSIGNED NULL,
    notes             TEXT NULL,
    requested_by      BIGINT UNSIGNED NOT NULL,
    approved_by       BIGINT UNSIGNED NULL,
    approved_at       DATETIME NULL,
    rejection_reason  VARCHAR(255) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_adjustment_number (adjustment_number),
    KEY ix_adjustments_status (status, location_id),
    CONSTRAINT fk_adj_location FOREIGN KEY (location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_adj_count FOREIGN KEY (stock_count_id) REFERENCES stock_counts (id) ON DELETE SET NULL,
    CONSTRAINT fk_adj_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_adj_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE stock_adjustment_items (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stock_adjustment_id BIGINT UNSIGNED NOT NULL,
    batch_id            BIGINT UNSIGNED NOT NULL,
    quantity_change     DECIMAL(14,3) NOT NULL,
    reason              VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY ix_sai_adjustment (stock_adjustment_id),
    KEY ix_sai_batch (batch_id),
    CONSTRAINT fk_sai_adjustment FOREIGN KEY (stock_adjustment_id) REFERENCES stock_adjustments (id) ON DELETE CASCADE,
    CONSTRAINT fk_sai_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT,
    CONSTRAINT ck_sai_qty CHECK (quantity_change <> 0)
) ENGINE=InnoDB;
