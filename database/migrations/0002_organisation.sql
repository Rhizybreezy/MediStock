-- =====================================================================
-- 0002 Departments, user-department assignments, storage locations
-- =====================================================================

CREATE TABLE departments (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             VARCHAR(20)  NOT NULL,
    name             VARCHAR(100) NOT NULL,
    type             ENUM('central_store','pharmacy','clinical','laboratory','theatre','equipment_store','other') NOT NULL DEFAULT 'clinical',
    is_central_store TINYINT(1) NOT NULL DEFAULT 0,
    description      VARCHAR(255) NULL,
    head_user_id     BIGINT UNSIGNED NULL,
    phone_extension  VARCHAR(20) NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_code (code),
    UNIQUE KEY uq_departments_name (name),
    KEY ix_departments_active (is_active),
    CONSTRAINT fk_departments_head FOREIGN KEY (head_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE user_departments (
    user_id       BIGINT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    is_primary    TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, department_id),
    KEY ix_user_departments_dept (department_id),
    CONSTRAINT fk_user_departments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_departments_dept FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Physical places where stock sits. Balances are kept per (location, batch).
-- type 'transit' = stock dispatched but not yet confirmed received.
-- type 'quarantine' = expired / recalled / suspect stock, never issued.
CREATE TABLE inventory_locations (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id INT UNSIGNED NOT NULL,
    code          VARCHAR(30)  NOT NULL,
    name          VARCHAR(100) NOT NULL,
    type          ENUM('store','shelf','refrigerator','freezer','cabinet','quarantine','transit') NOT NULL DEFAULT 'store',
    is_cold_chain TINYINT(1) NOT NULL DEFAULT 0,
    min_temp_c    DECIMAL(5,2) NULL,
    max_temp_c    DECIMAL(5,2) NULL,
    description   VARCHAR(255) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_code (code),
    UNIQUE KEY uq_locations_dept_name (department_id, name),
    KEY ix_locations_type (type),
    CONSTRAINT fk_locations_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT ck_locations_temp CHECK (min_temp_c IS NULL OR max_temp_c IS NULL OR min_temp_c < max_temp_c)
) ENGINE=InnoDB;
