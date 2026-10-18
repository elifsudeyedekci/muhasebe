-- FunBreak Ön Muhasebe — 001 çekirdek: kiracılar, kullanıcılar, oturumlar, güvenlik, denetim
-- Tüm tutarlar "küçük birim" (kuruş/cent) cinsinden BIGINT olarak saklanır; kayan nokta kullanılmaz.

CREATE TABLE tenants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  company_name VARCHAR(200) NULL,
  phone VARCHAR(40) NULL,
  status ENUM('active','suspended','archived') NOT NULL DEFAULT 'active',
  settings MEDIUMTEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  last_activity_at DATETIME NULL,
  archived_at DATETIME NULL,
  KEY ix_tenants_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  email VARCHAR(190) NOT NULL,
  display_name VARCHAR(160) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','customer') NOT NULL,
  -- Veritabanı seviyesinde tek yönetici: yalnızca admin için 1, diğerleri NULL (UNIQUE NULL'a izin verir)
  admin_flag TINYINT NULL,
  status ENUM('active','suspended','archived') NOT NULL DEFAULT 'active',
  must_change_password TINYINT NOT NULL DEFAULT 0,
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(64) NULL,
  prev_login_at DATETIME NULL,
  prev_login_ip VARCHAR(64) NULL,
  failed_attempts INT NOT NULL DEFAULT 0,
  lock_level INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  totp_secret_enc TEXT NULL,
  totp_enabled TINYINT NOT NULL DEFAULT 0,
  totp_last_step BIGINT NULL,
  recovery_codes TEXT NULL,
  session_epoch INT NOT NULL DEFAULT 1,
  password_changed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY ux_users_email (email),
  UNIQUE KEY ux_users_admin (admin_flag),
  KEY ix_users_tenant (tenant_id),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  prev_token_hash CHAR(64) NULL,
  prev_valid_until DATETIME NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  epoch INT NOT NULL,
  csrf_secret CHAR(64) NOT NULL,
  ip VARCHAR(64) NOT NULL,
  ua_hash CHAR(64) NOT NULL,
  label VARCHAR(160) NULL,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  rotated_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY ux_sessions_token (token_hash),
  KEY ix_sessions_prev (prev_token_hash),
  KEY ix_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  ip VARCHAR(64) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  UNIQUE KEY ux_tickets_token (token_hash),
  CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE captchas (
  id CHAR(32) NOT NULL PRIMARY KEY,
  answer_hash CHAR(64) NOT NULL,
  pow_seed CHAR(32) NOT NULL,
  pow_bits TINYINT NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  KEY ix_captcha_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  k VARCHAR(120) NOT NULL PRIMARY KEY,
  hits INT NOT NULL,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_tokens (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('reset','invite') NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY ix_pt_user (user_id),
  CONSTRAINT fk_pt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eklemeli (append-only) denetim kaydı; her satır önceki satırın hash'ine zincirlenir.
CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_role VARCHAR(20) NULL,
  tenant_id BIGINT UNSIGNED NULL,
  category ENUM('security','admin','data','access','system') NOT NULL,
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(60) NULL,
  entity_id VARCHAR(40) NULL,
  details MEDIUMTEXT NULL,
  ip VARCHAR(64) NULL,
  prev_hash CHAR(64) NOT NULL,
  hash CHAR(64) NOT NULL,
  KEY ix_audit_tenant (tenant_id, id),
  KEY ix_audit_cat (category, id),
  KEY ix_audit_actor (actor_user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE idempotency_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  ikey CHAR(64) NOT NULL,
  route VARCHAR(160) NOT NULL,
  status_code SMALLINT NULL,
  response MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY ux_idem (tenant_id, ikey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
  k VARCHAR(80) NOT NULL PRIMARY KEY,
  v MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
