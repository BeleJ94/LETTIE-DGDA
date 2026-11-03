-- Lettie-DGDA — schéma initial : organisation, utilisateurs, correspondances, dossier électronique, chronologie

CREATE TABLE units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    kind ENUM('direction','sous_direction','service','bureau') NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    is_mail_center TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_unit_parent FOREIGN KEY (parent_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','central','chief','agent','auditor') NOT NULL DEFAULT 'agent',
    unit_id INT UNSIGNED NULL,
    clearance TINYINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_unit FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    entity VARCHAR(40) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_activity_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE correspondents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    kind ENUM('administration','company','person','other') NOT NULL DEFAULT 'company',
    email VARCHAR(190) NULL,
    phone VARCHAR(60) NULL,
    address VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_corr_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numérotation chronologique sans trou : un compteur par sens et par année
CREATE TABLE mail_sequences (
    direction ENUM('in','out') NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    last_no INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (direction, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('in','out') NOT NULL,
    reference VARCHAR(40) NOT NULL UNIQUE,
    track_token CHAR(32) NOT NULL UNIQUE,
    parent_id INT UNSIGNED NULL,
    correspondent_id INT UNSIGNED NOT NULL,
    external_ref VARCHAR(100) NULL,
    letter_date DATE NULL,
    event_at DATETIME NOT NULL,
    channel ENUM('hand','post','email','courier','other') NOT NULL DEFAULT 'hand',
    subject VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    priority ENUM('normal','urgent','very_urgent') NOT NULL DEFAULT 'normal',
    confidentiality TINYINT UNSIGNED NOT NULL DEFAULT 0,
    entry_point ENUM('central','direct') NOT NULL,
    registered_unit_id INT UNSIGNED NOT NULL,
    unit_id INT UNSIGNED NOT NULL,
    due_on DATE NULL,
    status ENUM('registered','assigned','in_progress','answered','closed','draft','sent') NOT NULL,
    ack_at DATETIME NULL,
    ack_channel ENUM('email','print') NULL,
    ack_by INT UNSIGNED NULL,
    sent_at DATETIME NULL,
    closed_at DATETIME NULL,
    closed_by INT UNSIGNED NULL,
    close_note VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mail_status (direction, status),
    KEY idx_mail_unit (unit_id),
    KEY idx_mail_due (due_on),
    CONSTRAINT fk_mail_parent FOREIGN KEY (parent_id) REFERENCES mails(id),
    CONSTRAINT fk_mail_corr FOREIGN KEY (correspondent_id) REFERENCES correspondents(id),
    CONSTRAINT fk_mail_reg_unit FOREIGN KEY (registered_unit_id) REFERENCES units(id),
    CONSTRAINT fk_mail_unit FOREIGN KEY (unit_id) REFERENCES units(id),
    CONSTRAINT fk_mail_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mail_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mail_id INT UNSIGNED NOT NULL,
    kind ENUM('action','copy') NOT NULL DEFAULT 'action',
    from_unit_id INT UNSIGNED NOT NULL,
    to_unit_id INT UNSIGNED NOT NULL,
    instruction VARCHAR(500) NULL,
    due_on DATE NULL,
    assigned_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assign_unit (to_unit_id),
    CONSTRAINT fk_assign_mail FOREIGN KEY (mail_id) REFERENCES mails(id),
    CONSTRAINT fk_assign_from FOREIGN KEY (from_unit_id) REFERENCES units(id),
    CONSTRAINT fk_assign_to FOREIGN KEY (to_unit_id) REFERENCES units(id),
    CONSTRAINT fk_assign_by FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dossier électronique : courrier scanné, pièces, accusé de réception, réponse, preuves d'envoi
CREATE TABLE mail_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mail_id INT UNSIGNED NOT NULL,
    kind ENUM('original','annex','ack','reply','proof') NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_doc_mail FOREIGN KEY (mail_id) REFERENCES mails(id),
    CONSTRAINT fk_doc_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chronologie : chaque ligne est scellée par le hash de la précédente, et la base refuse toute modification
CREATE TABLE mail_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mail_id INT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    detail VARCHAR(500) NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(120) NOT NULL,
    unit_name VARCHAR(160) NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    prev_hash CHAR(64) NOT NULL,
    hash CHAR(64) NOT NULL,
    KEY idx_event_mail (mail_id, id),
    CONSTRAINT fk_event_mail FOREIGN KEY (mail_id) REFERENCES mails(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER mail_events_no_update BEFORE UPDATE ON mail_events FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mail_events is append-only';

CREATE TRIGGER mail_events_no_delete BEFORE DELETE ON mail_events FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mail_events is append-only';
