-- Configuration de la messagerie, suivi des e-mails, annulation des projets, freinage des connexions

CREATE TABLE mail_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    host VARCHAR(190) NOT NULL DEFAULT '',
    port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    encryption ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
    username VARCHAR(190) NOT NULL DEFAULT '',
    password_enc TEXT NULL,
    from_email VARCHAR(190) NOT NULL DEFAULT '',
    from_name VARCHAR(190) NOT NULL DEFAULT '',
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mail_id INT UNSIGNED NULL,
    kind ENUM('ack','test') NOT NULL,
    recipient VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    error VARCHAR(500) NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_status (status, id),
    CONSTRAINT fk_email_mail FOREIGN KEY (mail_id) REFERENCES mails(id),
    CONSTRAINT fk_email_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mails MODIFY status ENUM('registered','assigned','in_progress','answered','closed','draft','sent','cancelled') NOT NULL;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempt (email, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
