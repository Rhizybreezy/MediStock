-- =====================================================================
-- 0008 Notifications, cold chain, equipment, AI conversations
-- =====================================================================

CREATE TABLE notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    type        VARCHAR(40)  NOT NULL,
    severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    title       VARCHAR(150) NOT NULL,
    message     VARCHAR(500) NOT NULL,
    link_url    VARCHAR(255) NULL,
    entity_type VARCHAR(60)  NULL,
    entity_id   BIGINT UNSIGNED NULL,
    dedupe_key  VARCHAR(150) NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    read_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_dedupe (user_id, dedupe_key),
    KEY ix_notifications_user (user_id, is_read, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE temperature_logs (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    location_id       INT UNSIGNED NOT NULL,
    temperature_c     DECIMAL(5,2) NOT NULL,
    humidity_pct      DECIMAL(5,2) NULL,
    is_out_of_range   TINYINT(1) NOT NULL DEFAULT 0,
    corrective_action VARCHAR(255) NULL,
    recorded_by       BIGINT UNSIGNED NOT NULL,
    recorded_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_temp_location (location_id, recorded_at),
    KEY ix_temp_out_of_range (is_out_of_range, recorded_at),
    CONSTRAINT fk_temp_location FOREIGN KEY (location_id) REFERENCES inventory_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_temp_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_temp_humidity CHECK (humidity_pct IS NULL OR (humidity_pct >= 0 AND humidity_pct <= 100))
) ENGINE=InnoDB;

CREATE TABLE equipment (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_tag                 VARCHAR(40) NOT NULL,
    product_id                BIGINT UNSIGNED NULL,
    name                      VARCHAR(150) NOT NULL,
    serial_number             VARCHAR(80) NULL,
    model                     VARCHAR(80) NULL,
    manufacturer              VARCHAR(150) NULL,
    department_id             INT UNSIGNED NOT NULL,
    location_id               INT UNSIGNED NULL,
    status                    ENUM('in_service','under_maintenance','out_of_service','disposed') NOT NULL DEFAULT 'in_service',
    purchase_date             DATE NULL,
    purchase_cost             DECIMAL(14,2) NULL,
    warranty_until            DATE NULL,
    maintenance_interval_days SMALLINT UNSIGNED NULL,
    next_maintenance_due      DATE NULL,
    notes                     TEXT NULL,
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_equipment_asset_tag (asset_tag),
    UNIQUE KEY uq_equipment_serial (serial_number),
    KEY ix_equipment_department (department_id, status),
    KEY ix_equipment_maintenance (next_maintenance_due),
    CONSTRAINT fk_equipment_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
    CONSTRAINT fk_equipment_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_equipment_location FOREIGN KEY (location_id) REFERENCES inventory_locations (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE equipment_maintenance (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id         BIGINT UNSIGNED NOT NULL,
    type                 ENUM('preventive','corrective','calibration','inspection') NOT NULL,
    performed_at         DATE NOT NULL,
    performed_by_user_id BIGINT UNSIGNED NULL,
    vendor               VARCHAR(150) NULL,
    cost                 DECIMAL(14,2) NULL,
    findings             TEXT NULL,
    next_due_date        DATE NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_maintenance_equipment (equipment_id, performed_at),
    CONSTRAINT fk_maint_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_user FOREIGN KEY (performed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- MediStock's OWN AI conversation history (independent of any other system)
CREATE TABLE ai_conversations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(150) NOT NULL DEFAULT 'New conversation',
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_ai_conv_user (user_id, updated_at),
    CONSTRAINT fk_ai_conv_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ai_messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role            ENUM('user','model','function_call','function_result') NOT NULL,
    content         MEDIUMTEXT NULL,
    function_name   VARCHAR(80) NULL,
    function_args   JSON NULL,
    function_status ENUM('ok','denied','invalid','error') NULL,
    prompt_tokens   INT UNSIGNED NULL,
    output_tokens   INT UNSIGNED NULL,
    latency_ms      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_ai_msg_conversation (conversation_id, id),
    KEY ix_ai_msg_created (created_at),
    CONSTRAINT fk_ai_msg_conversation FOREIGN KEY (conversation_id) REFERENCES ai_conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB;
