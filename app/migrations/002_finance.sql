-- 002 finans modülleri. Her tabloda tenant_id (müşteri sahipliği) vardır; tüm sorgular tenant_id ile süzülür.

CREATE TABLE workspaces (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind ENUM('business','personal') NOT NULL DEFAULT 'business',
  legal_name VARCHAR(200) NULL,
  tax_no VARCHAR(30) NULL,
  color VARCHAR(9) NULL,
  archived TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY ux_ws_name (tenant_id, name),
  CONSTRAINT fk_ws_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NULL,
  contact_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  contract_minor BIGINT NOT NULL DEFAULT 0,
  est_cost_minor BIGINT NOT NULL DEFAULT 0,
  status ENUM('planned','active','done','cancelled') NOT NULL DEFAULT 'active',
  start_date DATE NULL,
  end_date DATE NULL,
  note TEXT NULL,
  archived TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY ix_pr_t (tenant_id),
  CONSTRAINT fk_pr_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  kind ENUM('bank','cash') NOT NULL DEFAULT 'bank',
  bank_name VARCHAR(120) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  iban VARCHAR(40) NULL,
  opening_minor BIGINT NOT NULL DEFAULT 0,
  opening_date DATE NOT NULL,
  is_default TINYINT NOT NULL DEFAULT 0,
  archived TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY ix_ac_t (tenant_id),
  CONSTRAINT fk_ac_t FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_ac_w FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('income','expense') NOT NULL,
  name VARCHAR(100) NOT NULL,
  archived TINYINT NOT NULL DEFAULT 0,
  UNIQUE KEY ux_cat (tenant_id, kind, name),
  CONSTRAINT fk_cat_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('customer','supplier','both') NOT NULL DEFAULT 'customer',
  name VARCHAR(200) NOT NULL,
  tax_no VARCHAR(30) NULL,
  tax_office VARCHAR(100) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  address VARCHAR(400) NULL,
  note TEXT NULL,
  archived TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY ix_ct_t (tenant_id),
  CONSTRAINT fk_ct_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('sales','purchase') NOT NULL,
  number VARCHAR(60) NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  issue_date DATE NOT NULL,
  due_date DATE NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  status ENUM('planned','issued','cancelled') NOT NULL DEFAULT 'issued',
  subtotal_minor BIGINT NOT NULL DEFAULT 0,
  vat_minor BIGINT NOT NULL DEFAULT 0,
  total_minor BIGINT NOT NULL DEFAULT 0,
  note TEXT NULL,
  cancel_reason VARCHAR(300) NULL,
  cancelled_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  -- numara girildiyse aynı cari + tür + numara ikinci kez kaydedilemez (mükerrer alacak/borç engeli)
  dedupe_key VARCHAR(300) NULL,
  UNIQUE KEY ux_inv_dedupe (tenant_id, dedupe_key),
  KEY ix_inv_t (tenant_id, issue_date),
  CONSTRAINT fk_inv_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('product','service') NOT NULL DEFAULT 'product',
  code VARCHAR(60) NULL,
  name VARCHAR(200) NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'adet',
  barcode VARCHAR(60) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  sale_price_minor BIGINT NOT NULL DEFAULT 0,
  vat_rate_bp INT NOT NULL DEFAULT 2000,
  min_qty_milli BIGINT NOT NULL DEFAULT 0,
  archived TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY ux_si_code (tenant_id, code),
  CONSTRAINT fk_si_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE warehouses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  archived TINYINT NOT NULL DEFAULT 0,
  UNIQUE KEY ux_wh (tenant_id, name),
  CONSTRAINT fk_wh_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  seq INT NOT NULL,
  description VARCHAR(300) NOT NULL,
  item_id BIGINT UNSIGNED NULL,
  warehouse_id BIGINT UNSIGNED NULL,
  qty_milli BIGINT NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'adet',
  unit_price_minor BIGINT NOT NULL,
  discount_bp INT NOT NULL DEFAULT 0,
  vat_rate_bp INT NOT NULL DEFAULT 0,
  net_minor BIGINT NOT NULL,
  vat_minor BIGINT NOT NULL,
  total_minor BIGINT NOT NULL,
  KEY ix_il_inv (invoice_id),
  CONSTRAINT fk_il_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('in','out','return_in','return_out') NOT NULL,
  qty_milli BIGINT NOT NULL,
  unit_cost_minor BIGINT NOT NULL DEFAULT 0,
  move_date DATE NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  invoice_id BIGINT UNSIGNED NULL,
  note VARCHAR(300) NULL,
  status ENUM('posted','voided') NOT NULL DEFAULT 'posted',
  created_at DATETIME NOT NULL,
  KEY ix_sm_t (tenant_id, item_id),
  KEY ix_sm_inv (invoice_id),
  CONSTRAINT fk_sm_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  entry_kind ENUM('receivable','payable') NOT NULL,
  title VARCHAR(200) NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  category_id BIGINT UNSIGNED NULL,
  contact_id BIGINT UNSIGNED NULL,
  frequency ENUM('weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
  next_date DATE NOT NULL,
  anchor_day TINYINT NOT NULL DEFAULT 1,
  end_date DATE NULL,
  active TINYINT NOT NULL DEFAULT 1,
  last_generated DATE NULL,
  created_at DATETIME NOT NULL,
  KEY ix_rt_t (tenant_id),
  CONSTRAINT fk_rt_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alacak / borç kalemleri (tahakkuk). Ödenen tutar transactions.entry_id üzerinden hesaplanır.
CREATE TABLE entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('receivable','payable') NOT NULL,
  source ENUM('manual','invoice','personal_bill','recurring','milestone') NOT NULL DEFAULT 'manual',
  contact_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NULL,
  category_id BIGINT UNSIGNED NULL,
  invoice_id BIGINT UNSIGNED NULL,
  recurring_id BIGINT UNSIGNED NULL,
  group_id VARCHAR(26) NULL,
  seq INT NULL,
  seq_total INT NULL,
  title VARCHAR(200) NOT NULL,
  provider VARCHAR(120) NULL,
  period VARCHAR(20) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  amount_minor BIGINT NOT NULL,
  due_date DATE NOT NULL,
  original_due_date DATE NOT NULL,
  priority TINYINT NOT NULL DEFAULT 2,
  affects_pl TINYINT NOT NULL DEFAULT 0,
  status ENUM('open','cancelled') NOT NULL DEFAULT 'open',
  note TEXT NULL,
  created_at DATETIME NOT NULL,
  cancelled_at DATETIME NULL,
  -- aynı faturadan ikinci kalem oluşmasını veritabanı da engeller
  invoice_key VARCHAR(40) NULL,
  UNIQUE KEY ux_entry_invoice (tenant_id, invoice_key),
  UNIQUE KEY ux_entry_recur (tenant_id, recurring_id, due_date),
  KEY ix_en_t (tenant_id, status, due_date),
  CONSTRAINT fk_en_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  bank_name VARCHAR(120) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  limit_minor BIGINT NOT NULL DEFAULT 0,
  statement_day TINYINT NOT NULL DEFAULT 1,
  due_day TINYINT NOT NULL DEFAULT 10,
  archived TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_cd_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE card_purchases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  card_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  txn_date DATE NOT NULL,
  description VARCHAR(250) NOT NULL,
  amount_minor BIGINT NOT NULL,
  vat_rate_bp INT NOT NULL DEFAULT 0,
  vat_minor BIGINT NOT NULL DEFAULT 0,
  installments INT NOT NULL DEFAULT 1,
  category_id BIGINT UNSIGNED NULL,
  contact_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NULL,
  status ENUM('posted','voided') NOT NULL DEFAULT 'posted',
  created_at DATETIME NOT NULL,
  KEY ix_cp_t (tenant_id, card_id),
  CONSTRAINT fk_cp_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE card_installments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  card_id BIGINT UNSIGNED NOT NULL,
  purchase_id BIGINT UNSIGNED NOT NULL,
  seq INT NOT NULL,
  due_date DATE NOT NULL,
  amount_minor BIGINT NOT NULL,
  KEY ix_ci_t (tenant_id, card_id, due_date),
  CONSTRAINT fk_ci_p FOREIGN KEY (purchase_id) REFERENCES card_purchases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  bank_name VARCHAR(120) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  principal_minor BIGINT NOT NULL,
  monthly_rate_bp INT NOT NULL DEFAULT 0,
  term_months INT NOT NULL,
  start_date DATE NOT NULL,
  status ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_ln_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_installments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  loan_id BIGINT UNSIGNED NOT NULL,
  seq INT NOT NULL,
  due_date DATE NOT NULL,
  principal_minor BIGINT NOT NULL,
  interest_minor BIGINT NOT NULL,
  KEY ix_li_t (tenant_id, loan_id),
  CONSTRAINT fk_li_l FOREIGN KEY (loan_id) REFERENCES loans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE statement_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  line_date DATE NOT NULL,
  description VARCHAR(300) NULL,
  amount_minor BIGINT NOT NULL,            -- işaretli: + giriş, - çıkış
  line_hash CHAR(64) NOT NULL,
  matched_txn_id BIGINT UNSIGNED NULL,
  status ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
  created_at DATETIME NOT NULL,
  UNIQUE KEY ux_sl_hash (tenant_id, account_id, line_hash),
  CONSTRAINT fk_sl_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gerçekleşen para hareketleri (defter). Satırlar silinmez/üzerine yazılmaz; iptal = status 'voided'.
CREATE TABLE transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('in','out') NOT NULL,
  kind ENUM('income','expense','collection','payment','transfer','fx','card_payment','loan_payment','loan_proceeds','adjustment') NOT NULL,
  amount_minor BIGINT NOT NULL,            -- her zaman > 0, KDV dahil brüt
  interest_minor BIGINT NOT NULL DEFAULT 0,-- loan_payment içindeki faiz (gider sayılır)
  currency CHAR(3) NOT NULL,
  txn_date DATE NOT NULL,
  description VARCHAR(300) NULL,
  category_id BIGINT UNSIGNED NULL,
  contact_id BIGINT UNSIGNED NULL,
  project_id BIGINT UNSIGNED NULL,
  entry_id BIGINT UNSIGNED NULL,
  card_id BIGINT UNSIGNED NULL,
  loan_id BIGINT UNSIGNED NULL,
  loan_installment_id BIGINT UNSIGNED NULL,
  transfer_group VARCHAR(26) NULL,
  fx_rate DECIMAL(18,8) NULL,
  vat_rate_bp INT NOT NULL DEFAULT 0,
  vat_minor BIGINT NOT NULL DEFAULT 0,
  status ENUM('posted','voided') NOT NULL DEFAULT 'posted',
  void_reason VARCHAR(300) NULL,
  voided_at DATETIME NULL,
  voided_by BIGINT UNSIGNED NULL,
  replaces_id BIGINT UNSIGNED NULL,
  statement_line_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY ix_tx_acc (tenant_id, account_id, status),
  KEY ix_tx_date (tenant_id, txn_date),
  KEY ix_tx_entry (entry_id),
  KEY ix_tx_group (transfer_group),
  CONSTRAINT fk_tx_t FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_tx_a FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_quotes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('quote','order') NOT NULL DEFAULT 'quote',
  number VARCHAR(60) NULL,
  quote_date DATE NOT NULL,
  amount_minor BIGINT NOT NULL,
  status ENUM('draft','sent','accepted','rejected') NOT NULL DEFAULT 'draft',
  note VARCHAR(400) NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_pq_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_milestones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  due_date DATE NOT NULL,
  amount_minor BIGINT NOT NULL,
  entry_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_pm_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budgets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  month CHAR(7) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  amount_minor BIGINT NOT NULL,
  UNIQUE KEY ux_bud (tenant_id, category_id, month, currency),
  CONSTRAINT fk_bu_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fx_rates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  rate_date DATE NOT NULL,
  rate DECIMAL(18,8) NOT NULL,             -- 1 birim döviz = rate TRY
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  UNIQUE KEY ux_fx (tenant_id, currency, rate_date),
  CONSTRAINT fk_fx_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  entity VARCHAR(30) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  orig_name VARCHAR(200) NOT NULL,
  stored_name CHAR(64) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes BIGINT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  KEY ix_doc_ent (tenant_id, entity, entity_id),
  CONSTRAINT fk_doc_t FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
