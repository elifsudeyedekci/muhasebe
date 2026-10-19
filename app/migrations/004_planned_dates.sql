-- 004 planlanan ödeme/tahsilat tarihi: fatura vadesinden AYRI, isteğe bağlı (NULL = tarih girilmemiş; sistem tarih üretmez).
ALTER TABLE entries ADD COLUMN planned_date DATE NULL;

ALTER TABLE entries ADD KEY ix_en_planned (tenant_id, status, planned_date);
