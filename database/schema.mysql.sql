-- MySQL 8.0 schema for the Senior Care Platform
-- Charset: utf8mb4 (Cyrillic + emoji-safe), engine: InnoDB

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id INT UNSIGNED NOT NULL,
    phone VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    token_version INT NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_phone (phone),
    KEY idx_users_role_id (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS elderly_profiles (
    user_id INT UNSIGNED NOT NULL,
    address TEXT NOT NULL,
    latitude DOUBLE NOT NULL,
    longitude DOUBLE NOT NULL,
    birth_year INT NULL,
    mobility_notes TEXT NULL,
    emergency_contact VARCHAR(100) NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_elderly_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS volunteer_profiles (
    user_id INT UNSIGNED NOT NULL,
    is_verified INT DEFAULT 0,
    rating DOUBLE DEFAULT 5.0,
    points INT DEFAULT 0,
    latitude DOUBLE NOT NULL,
    longitude DOUBLE NOT NULL,
    radius_km DOUBLE DEFAULT 5.0,
    skills TEXT NULL,
    completed_tasks_count INT DEFAULT 0,
    verification_status VARCHAR(50) DEFAULT 'PENDING',
    id_provider VARCHAR(50) NULL,
    id_provider_user_id VARCHAR(100) NULL,
    passport_masked VARCHAR(50) NULL,
    police_clearance_status VARCHAR(50) DEFAULT 'NOT_SUBMITTED',
    badge_token VARCHAR(64) NULL,
    badge_expires_at DATETIME NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_volunteer_badge_token (badge_token),
    KEY idx_volunteer_profiles_status (verification_status),
    CONSTRAINT fk_volunteer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    icon VARCHAR(50) NOT NULL,
    description TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS help_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    elderly_id INT UNSIGNED NOT NULL,
    volunteer_id INT UNSIGNED NULL,
    category_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    latitude DOUBLE NOT NULL,
    longitude DOUBLE NOT NULL,
    address TEXT NOT NULL,
    secret_code VARCHAR(50) NOT NULL,
    status VARCHAR(50) DEFAULT 'open',
    volunteer_feedback TEXT NULL,
    rating_score INT NULL,
    completed_lat DOUBLE NULL,
    completed_lon DOUBLE NULL,
    distance_to_target DOUBLE NULL,
    is_overdue INT DEFAULT 0,
    assigned_at DATETIME NULL,
    failed_attempts INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_help_requests_elderly (elderly_id),
    KEY idx_help_requests_volunteer (volunteer_id),
    KEY idx_help_requests_category (category_id),
    KEY idx_help_requests_status (status),
    KEY idx_help_requests_is_overdue (is_overdue),
    KEY idx_help_requests_assigned_at (assigned_at),
    CONSTRAINT fk_requests_elderly FOREIGN KEY (elderly_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_requests_volunteer FOREIGN KEY (volunteer_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_requests_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    message_text TEXT NOT NULL,
    is_read INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chat_request (request_id),
    KEY idx_chat_sender (sender_id),
    CONSTRAINT fk_chat_request FOREIGN KEY (request_id) REFERENCES help_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS educational_materials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    category VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    audio_url VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fraud_alerts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    severity VARCHAR(50) DEFAULT 'warning',
    is_active INT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS volunteer_achievements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    volunteer_id INT UNSIGNED NOT NULL,
    badge_code VARCHAR(50) NOT NULL,
    badge_name VARCHAR(150) NOT NULL,
    earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_achievements_volunteer (volunteer_id),
    CONSTRAINT fk_achievements_volunteer FOREIGN KEY (volunteer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_consents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    consent_type VARCHAR(100) NOT NULL,
    policy_version VARCHAR(50) DEFAULT '1.0.0',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_user_consents_user_id (user_id),
    KEY idx_user_consents_type (consent_type),
    CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sos_alerts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    elderly_id INT UNSIGNED NOT NULL,
    latitude DOUBLE NOT NULL,
    longitude DOUBLE NOT NULL,
    address TEXT NOT NULL,
    reason TEXT NULL,
    status VARCHAR(50) DEFAULT 'ACTIVE',
    priority INT DEFAULT 0,
    emercom_ticket_id VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_sos_alerts_elderly_id (elderly_id),
    KEY idx_sos_alerts_status (status),
    KEY idx_sos_alerts_priority (priority),
    KEY idx_sos_alerts_created_at (created_at),
    CONSTRAINT fk_sos_elderly FOREIGN KEY (elderly_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_password_resets_user_id (user_id),
    KEY idx_password_resets_expires_at (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
