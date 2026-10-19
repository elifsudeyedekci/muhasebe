-- 003 ödeme dağıtımı (tek ödeme → çok fatura / cariye bağımsız), isteğe bağlı vade, teslimat takibi, şahsi hesap ödemeleri.
-- YALNIZCA EKLEYEN migration: mevcut satır silinmez/dönüştürülmez. Var olan "ödeme → kalem" bağları payment_allocations tablosuna AYNEN kopyalanır.

-- Vade zorunlu değil: kalem vadesiz olabilir (NULL = vadesiz; gecikmiş sayılmaz). Mevcut vadeler değişmez.
ALTER TABLE entries MODIFY due_date DATE NULL;

ALTER TABLE entries MODIFY original_due_date DATE NULL;

-- Ödeme (para hareketi) ile fatura/kalem eşleştirmesi ayrı tablo: para hareketi bir kez, dağıtım çok kez olabilir.
CREATE TABLE payment_allocations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  txn_id BIGINT UNSIGNED NOT NULL,
  entry_id BIGINT UNSIGNED NOT NULL,
  amount_minor BIGINT NOT NULL,
  status ENUM('active','reversed') NOT NULL DEFAULT 'active',
  note VARCHAR(300) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  reversed_at DATETIME NULL,
  reversed_by BIGINT UNSIGNED NULL,
  KEY ix_pa_txn (tenant_id, txn_id, status),
  KEY ix_pa_entry (tenant_id, entry_id, status),
  CONSTRAINT fk_pa_t FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_pa_txn FOREIGN KEY (txn_id) REFERENCES transactions(id),
  CONSTRAINT fk_pa_entry FOREIGN KEY (entry_id) REFERENCES entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geçmiş: transactions.entry_id ile bağlı her ödeme için tek bir aktif dağıtım (tutar birebir aynı).
INSERT INTO payment_allocations (tenant_id, txn_id, entry_id, amount_minor, status, note, created_by, created_at)
  SELECT tenant_id, id, entry_id, amount_minor, 'active', 'Geçiş: mevcut ödeme bağlantısı', created_by, created_at FROM transactions WHERE entry_id IS NOT NULL;

-- Ödeme açıklama/izlenebilirlik alanları
ALTER TABLE transactions ADD COLUMN reason VARCHAR(300) NULL;

ALTER TABLE transactions ADD COLUMN reference VARCHAR(100) NULL;

ALTER TABLE transactions ADD COLUMN method VARCHAR(30) NULL;

-- Şahsi hesaptan işletme adına yapılan ödemede hedef işletme alanı (işletme bankası etkilenmez; ortak cari hesabını besler)
ALTER TABLE transactions ADD COLUMN for_workspace_id BIGINT UNSIGNED NULL;

ALTER TABLE transactions MODIFY kind ENUM('income','expense','collection','payment','transfer','fx','card_payment','loan_payment','loan_proceeds','adjustment','owner_in','owner_out') NOT NULL;

ALTER TABLE transactions ADD KEY ix_tx_contact (tenant_id, contact_id);

ALTER TABLE transactions ADD KEY ix_tx_forws (tenant_id, for_workspace_id);

-- Teslimat takibi: satırda stoktan gerçekten düşülen/girilen miktar. Fatura kesme ile teslimat ayrı izlenir.
ALTER TABLE invoice_lines ADD COLUMN fulfilled_milli BIGINT NOT NULL DEFAULT 0;

ALTER TABLE invoices ADD COLUMN defer_delivery TINYINT NOT NULL DEFAULT 0;

-- Geçmiş: kesilmiş faturaların stok takipli satırları tam işlenmiş sayılır (mevcut davranışla birebir aynı).
UPDATE invoice_lines l JOIN invoices i ON i.id = l.invoice_id JOIN stock_items s ON s.id = l.item_id
  SET l.fulfilled_milli = l.qty_milli
  WHERE i.status = 'issued' AND s.kind = 'product' AND l.warehouse_id IS NOT NULL;

