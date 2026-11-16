-- =====================================================================
-- 0004 Suppliers, purchase requests, purchase orders
-- =====================================================================

CREATE TABLE suppliers (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_code          VARCHAR(20)  NOT NULL,
    name                   VARCHAR(150) NOT NULL,
    contact_person         VARCHAR(120) NULL,
    phone                  VARCHAR(30)  NULL,
    email                  VARCHAR(150) NULL,
    address                VARCHAR(255) NULL,
    tax_number             VARCHAR(50)  NULL,
    payment_terms          VARCHAR(100) NULL,
    default_lead_time_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    status                 ENUM('active','inactive','blacklisted') NOT NULL DEFAULT 'active',
    rating_cache           DECIMAL(3,2) NULL,
    notes                  TEXT NULL,
    created_by             BIGINT UNSIGNED NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_code (supplier_code),
    UNIQUE KEY uq_suppliers_name (name),
    KEY ix_suppliers_status (status),
    CONSTRAINT fk_suppliers_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Which supplier offers which product, current quote and lead time.
-- Historic prices actually paid are derived from purchase_order_items.
CREATE TABLE supplier_products (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id       INT UNSIGNED NOT NULL,
    product_id        BIGINT UNSIGNED NOT NULL,
    supplier_sku      VARCHAR(60) NULL,
    quoted_unit_price DECIMAL(14,4) NULL,
    quote_valid_until DATE NULL,
    lead_time_days    SMALLINT UNSIGNED NULL,
    is_preferred      TINYINT(1) NOT NULL DEFAULT 0,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_products (supplier_id, product_id),
    KEY ix_supplier_products_product (product_id),
    CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE,
    CONSTRAINT fk_sp_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE purchase_requests (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pr_number        VARCHAR(30) NOT NULL,
    department_id    INT UNSIGNED NOT NULL,
    source           ENUM('manual','reorder_suggestion','ai_proposal') NOT NULL DEFAULT 'manual',
    priority         ENUM('routine','urgent','emergency') NOT NULL DEFAULT 'routine',
    status           ENUM('draft','submitted','approved','rejected','partially_ordered','ordered','cancelled') NOT NULL DEFAULT 'draft',
    justification    TEXT NULL,
    requested_by     BIGINT UNSIGNED NOT NULL,
    submitted_at     DATETIME NULL,
    approved_by      BIGINT UNSIGNED NULL,
    approved_at      DATETIME NULL,
    rejection_reason VARCHAR(255) NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_number (pr_number),
    KEY ix_pr_status (status, department_id),
    CONSTRAINT fk_pr_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE purchase_request_items (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_request_id   BIGINT UNSIGNED NOT NULL,
    product_id            BIGINT UNSIGNED NOT NULL,
    quantity              DECIMAL(14,3) NOT NULL,
    estimated_unit_cost   DECIMAL(14,4) NULL,
    preferred_supplier_id INT UNSIGNED NULL,
    ordered_qty           DECIMAL(14,3) NOT NULL DEFAULT 0,
    notes                 VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_items (purchase_request_id, product_id),
    KEY ix_pr_items_product (product_id),
    CONSTRAINT fk_pri_pr FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_pri_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pri_supplier FOREIGN KEY (preferred_supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
    CONSTRAINT ck_pri_qty CHECK (quantity > 0 AND ordered_qty >= 0)
) ENGINE=InnoDB;

CREATE TABLE purchase_orders (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    po_number              VARCHAR(30) NOT NULL,
    supplier_id            INT UNSIGNED NOT NULL,
    status                 ENUM('draft','pending_approval','approved','sent','partially_received','received','closed','cancelled') NOT NULL DEFAULT 'draft',
    order_date             DATE NULL,
    expected_delivery_date DATE NULL,
    delivery_location_id   INT UNSIGNED NOT NULL,
    subtotal               DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_amount             DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_amount           DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes                  TEXT NULL,
    created_by             BIGINT UNSIGNED NOT NULL,
    approved_by            BIGINT UNSIGNED NULL,
    approved_at            DATETIME NULL,
    sent_at                DATETIME NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_po_number (po_number),
    KEY ix_po_status (status, expected_delivery_date),
    KEY ix_po_supplier (supplier_id, order_date),
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_location FOREIGN KEY (delivery_location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE purchase_order_items (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_order_id        BIGINT UNSIGNED NOT NULL,
    product_id               BIGINT UNSIGNED NOT NULL,
    purchase_request_item_id BIGINT UNSIGNED NULL,
    order_unit_id            INT UNSIGNED NOT NULL,
    unit_factor              DECIMAL(14,3) NOT NULL DEFAULT 1,
    ordered_qty_base         DECIMAL(14,3) NOT NULL,
    unit_price               DECIMAL(14,4) NOT NULL,
    line_total               DECIMAL(14,2) NOT NULL,
    received_qty_base        DECIMAL(14,3) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_poi_po (purchase_order_id),
    KEY ix_poi_product (product_id),
    CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_poi_pr_item FOREIGN KEY (purchase_request_item_id) REFERENCES purchase_request_items (id) ON DELETE SET NULL,
    CONSTRAINT fk_poi_unit FOREIGN KEY (order_unit_id) REFERENCES units_of_measure (id) ON DELETE RESTRICT,
    CONSTRAINT ck_poi_qty CHECK (ordered_qty_base > 0 AND received_qty_base >= 0 AND unit_price >= 0 AND unit_factor > 0)
) ENGINE=InnoDB;
