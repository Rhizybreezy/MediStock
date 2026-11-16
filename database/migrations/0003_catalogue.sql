-- =====================================================================
-- 0003 Product catalogue: categories, units, products, pack conversions,
--      per-department stock thresholds
-- =====================================================================

CREATE TABLE inventory_categories (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id       INT UNSIGNED NULL,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(100) NOT NULL,
    description     VARCHAR(255) NULL,
    requires_expiry TINYINT(1) NOT NULL DEFAULT 1,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_code (code),
    UNIQUE KEY uq_categories_name (name),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES inventory_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE units_of_measure (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(10) NOT NULL,
    name          VARCHAR(50) NOT NULL,
    allows_decimal TINYINT(1) NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_units_code (code),
    UNIQUE KEY uq_units_name (name)
) ENGINE=InnoDB;

-- All stock quantities are stored in the product's BASE unit.
CREATE TABLE products (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_code           VARCHAR(30)  NOT NULL,
    name                   VARCHAR(150) NOT NULL,
    generic_name           VARCHAR(150) NULL,
    category_id            INT UNSIGNED NOT NULL,
    base_unit_id           INT UNSIGNED NOT NULL,
    dosage_form            VARCHAR(60)  NULL,
    strength               VARCHAR(60)  NULL,
    manufacturer           VARCHAR(150) NULL,
    description            TEXT NULL,
    storage_condition      ENUM('room_temp','cool','refrigerated','frozen','controlled') NOT NULL DEFAULT 'room_temp',
    storage_notes          VARCHAR(255) NULL,
    is_batch_tracked       TINYINT(1) NOT NULL DEFAULT 1,
    is_expiry_tracked      TINYINT(1) NOT NULL DEFAULT 1,
    is_critical            TINYINT(1) NOT NULL DEFAULT 0,
    is_controlled          TINYINT(1) NOT NULL DEFAULT 0,
    min_level              DECIMAL(14,3) NOT NULL DEFAULT 0,
    reorder_level          DECIMAL(14,3) NOT NULL DEFAULT 0,
    max_level              DECIMAL(14,3) NOT NULL DEFAULT 0,
    default_lead_time_days SMALLINT UNSIGNED NULL,
    image_path             VARCHAR(255) NULL,
    is_active              TINYINT(1) NOT NULL DEFAULT 1,
    created_by             BIGINT UNSIGNED NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_code (product_code),
    KEY ix_products_name (name),
    KEY ix_products_generic (generic_name),
    KEY ix_products_category (category_id, is_active),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES inventory_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_products_base_unit FOREIGN KEY (base_unit_id) REFERENCES units_of_measure (id) ON DELETE RESTRICT,
    CONSTRAINT fk_products_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_products_levels CHECK (min_level >= 0 AND reorder_level >= min_level AND (max_level = 0 OR max_level >= reorder_level))
) ENGINE=InnoDB;

-- Pack conversions (1 BOX = 100 TAB) and manufacturer GTIN barcodes.
CREATE TABLE product_units (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id       BIGINT UNSIGNED NOT NULL,
    unit_id          INT UNSIGNED NOT NULL,
    factor_to_base   DECIMAL(14,3) NOT NULL,
    gtin_barcode     VARCHAR(50) NULL,
    is_purchase_unit TINYINT(1) NOT NULL DEFAULT 0,
    is_issue_unit    TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_units (product_id, unit_id),
    UNIQUE KEY uq_product_units_gtin (gtin_barcode),
    CONSTRAINT fk_product_units_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_product_units_unit FOREIGN KEY (unit_id) REFERENCES units_of_measure (id) ON DELETE RESTRICT,
    CONSTRAINT ck_product_units_factor CHECK (factor_to_base > 0)
) ENGINE=InnoDB;

-- Optional per-department thresholds; product defaults apply when no row exists.
CREATE TABLE department_product_settings (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id INT UNSIGNED NOT NULL,
    product_id    BIGINT UNSIGNED NOT NULL,
    min_level     DECIMAL(14,3) NOT NULL DEFAULT 0,
    reorder_level DECIMAL(14,3) NOT NULL DEFAULT 0,
    max_level     DECIMAL(14,3) NOT NULL DEFAULT 0,
    is_stocked    TINYINT(1) NOT NULL DEFAULT 1,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dept_product (department_id, product_id),
    KEY ix_dept_product_product (product_id),
    CONSTRAINT fk_dps_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE,
    CONSTRAINT fk_dps_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT ck_dps_levels CHECK (min_level >= 0 AND reorder_level >= min_level AND (max_level = 0 OR max_level >= reorder_level))
) ENGINE=InnoDB;
