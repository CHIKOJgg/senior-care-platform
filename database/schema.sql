-- Схема базы данных платформы помощи пожилым людям (Senior Care Platform)
-- Совместимо с SQLite 3 и MySQL 8.0+

CREATE TABLE IF NOT EXISTS roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_id INTEGER NOT NULL,
    phone VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    token_version INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS elderly_profiles (
    user_id INTEGER PRIMARY KEY,
    address TEXT NOT NULL,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    birth_year INTEGER,
    mobility_notes TEXT,
    emergency_contact VARCHAR(100),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS volunteer_profiles (
    user_id INTEGER PRIMARY KEY,
    is_verified INTEGER DEFAULT 0,
    rating REAL DEFAULT 5.0,
    points INTEGER DEFAULT 0,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    radius_km REAL DEFAULT 5.0,
    skills TEXT,
    completed_tasks_count INTEGER DEFAULT 0,
    verification_status VARCHAR(50) DEFAULT 'PENDING',
    id_provider VARCHAR(50) NULL,
    id_provider_user_id VARCHAR(100) NULL,
    passport_masked VARCHAR(50) NULL,
    police_clearance_status VARCHAR(50) DEFAULT 'NOT_SUBMITTED',
    badge_token VARCHAR(64) NULL,
    badge_expires_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(50) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    icon VARCHAR(50) NOT NULL,
    description TEXT
);

CREATE TABLE IF NOT EXISTS help_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    elderly_id INTEGER NOT NULL,
    volunteer_id INTEGER NULL,
    category_id INTEGER NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    address TEXT NOT NULL,
    secret_code VARCHAR(50) NOT NULL,
    status VARCHAR(50) DEFAULT 'open', -- open, in_progress, completed, cancelled
    volunteer_feedback TEXT NULL,
    rating_score INTEGER NULL,
    completed_lat REAL NULL,
    completed_lon REAL NULL,
    distance_to_target REAL NULL,
    is_overdue INTEGER DEFAULT 0,
    assigned_at DATETIME NULL,
    failed_attempts INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    FOREIGN KEY (elderly_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (volunteer_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    sender_id INTEGER NOT NULL,
    message_text TEXT NOT NULL,
    is_read INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES help_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS educational_materials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL, -- memo, audio, video
    category VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    audio_url VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS fraud_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    severity VARCHAR(50) DEFAULT 'warning', -- info, warning, danger
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS volunteer_achievements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    volunteer_id INTEGER NOT NULL,
    badge_code VARCHAR(50) NOT NULL,
    badge_name VARCHAR(150) NOT NULL,
    earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (volunteer_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Таблица согласий субъектов персональных данных (Закон РБ № 99-З)
CREATE TABLE IF NOT EXISTS user_consents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    consent_type VARCHAR(100) NOT NULL,
    policy_version VARCHAR(50) DEFAULT '1.0.0',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Таблица экстренных вызовов подопечных (Priority 0 SOS)
CREATE TABLE IF NOT EXISTS sos_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    elderly_id INTEGER NOT NULL,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    address TEXT NOT NULL,
    reason TEXT NULL,
    status VARCHAR(50) DEFAULT 'ACTIVE',
    priority INTEGER DEFAULT 0,
    emercom_ticket_id VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    FOREIGN KEY (elderly_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Одноразовые коды восстановления пароля (выдаёт координатор ТЦСОН)
CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by INTEGER NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Индексы для оптимизации поиска и фильтрации
CREATE INDEX IF NOT EXISTS idx_password_resets_user_id ON password_resets(user_id);
CREATE INDEX IF NOT EXISTS idx_password_resets_expires_at ON password_resets(expires_at);
CREATE INDEX IF NOT EXISTS idx_user_consents_user_id ON user_consents(user_id);
CREATE INDEX IF NOT EXISTS idx_user_consents_type ON user_consents(consent_type);
CREATE INDEX IF NOT EXISTS idx_sos_alerts_elderly_id ON sos_alerts(elderly_id);
CREATE INDEX IF NOT EXISTS idx_sos_alerts_status ON sos_alerts(status);
CREATE INDEX IF NOT EXISTS idx_sos_alerts_priority ON sos_alerts(priority);
CREATE INDEX IF NOT EXISTS idx_sos_alerts_created_at ON sos_alerts(created_at);
CREATE INDEX IF NOT EXISTS idx_help_requests_status ON help_requests(status);
CREATE INDEX IF NOT EXISTS idx_help_requests_is_overdue ON help_requests(is_overdue);
CREATE INDEX IF NOT EXISTS idx_help_requests_assigned_at ON help_requests(assigned_at);
CREATE INDEX IF NOT EXISTS idx_volunteer_profiles_status ON volunteer_profiles(verification_status);
CREATE UNIQUE INDEX IF NOT EXISTS idx_volunteer_profiles_badge_token ON volunteer_profiles(badge_token);
