-- =====================================================================
-- 0001 Identity, access control, settings, audit, numbering
-- =====================================================================

CREATE TABLE users (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name            VARCHAR(120) NOT NULL,
    username             VARCHAR(50)  NOT NULL,
    email                VARCHAR(150) NOT NULL,
    phone                VARCHAR(30)  NULL,
    password_hash        VARCHAR(255) NOT NULL,
    status               ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    failed_login_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until         DATETIME NULL,
    last_login_at        DATETIME NULL,
    last_login_ip        VARBINARY(16) NULL,
    password_changed_at  DATETIME NULL,
    created_by           BIGINT UNSIGNED NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY ix_users_status (status),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(50)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    scope       ENUM('global','department') NOT NULL DEFAULT 'department',
    is_system   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(80)  NOT NULL,
    module      VARCHAR(60)  NOT NULL,
    description VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code),
    KEY ix_permissions_module (module)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    KEY ix_user_roles_role (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(150) NOT NULL,
    ip_address   VARBINARY(16) NOT NULL,
    succeeded    TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_login_attempts_user (username, attempted_at),
    KEY ix_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    setting_key   VARCHAR(80) NOT NULL,
    setting_value TEXT NULL,
    value_type    ENUM('int','decimal','bool','string','json') NOT NULL DEFAULT 'string',
    setting_group VARCHAR(60) NOT NULL DEFAULT 'general',
    label         VARCHAR(120) NOT NULL,
    description   VARCHAR(255) NULL,
    sort_order    SMALLINT NOT NULL DEFAULT 0,
    updated_by    BIGINT UNSIGNED NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_system_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Append-only audit trail (production DB user should have INSERT/SELECT only on this table)
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NULL,
    action      VARCHAR(60)  NOT NULL,
    entity_type VARCHAR(60)  NULL,
    entity_id   BIGINT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    old_values  JSON NULL,
    new_values  JSON NULL,
    ip_address  VARBINARY(16) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_audit_entity (entity_type, entity_id),
    KEY ix_audit_user (user_id, created_at),
    KEY ix_audit_action (action, created_at),
    KEY ix_audit_created (created_at),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Gap-safe document numbering (PO-2026-000001). Row locked with SELECT ... FOR UPDATE.
CREATE TABLE document_sequences (
    name       VARCHAR(20) NOT NULL,
    seq_year   SMALLINT UNSIGNED NOT NULL,
    next_value INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (name, seq_year)
) ENGINE=InnoDB;
