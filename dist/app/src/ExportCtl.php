<?php
declare(strict_types=1);

namespace FB;

/** CSV (Excel uyumlu, UTF-8 BOM, ';' ayraçlı) ve XLSX dışa aktarma. PDF için arayüz yazdırma görünümü kullanılır. */
final class ExportCtl
{
    /** Para hücresi: ['m' => küçük birim] */
    private static function m(int $v): array
    {
        return ['m' => $v];
    }

    public static function run(Req $r, string $name, int $tid): never
    {
        $fmt = ($r->query['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        $q = $r->query;
        [$title, $head, $rows] = self::table($name, $q, $tid);
        Audit::log('data', 'export_' . $name, ['tenant' => $tid, 'details' => ['format' => $fmt, 'rows' => count($rows)]]);
        $file = preg_replace('/[^a-z0-9_-]/i', '', $name) . '-' . Util::today() . '.' . $fmt;
        Res::securityHeaders();
        header('Content-Disposition: attachment; filename="' . $file . '"');
        if ($fmt === 'xlsx') {
            if (!class_exists('ZipArchive')) {
                throw new ApiError('unsupported', 'Sunucuda ZIP desteği yok; CSV kullanın.', 501);
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            echo self::xlsx($title, $head, $rows);
            exit;
        }
        header('Content-Type: text/csv; charset=utf-8');
        echo "\xEF\xBB\xBF" . self::csvLine($head);
        foreach ($rows as $row) {
            echo self::csvLine(array_map(fn($c) => is_array($c) ? number_format($c['m'] / 100, 2, ',', '') : $c, $row));
        }
        exit;
    }

    private static function csvLine(array $cells): string
    {
        $o = [];
        foreach ($cells as $c) {
            $s = (string)($c ?? '');
            if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(,\d+)?$/', $s)) {
                $s = "'" . $s; // CSV/formül enjeksiyonu koruması
            }
            $o[] = '"' . str_replace('"', '""', $s) . '"';
        }
        return implode(';', $o) . "\r\n";
    }

    private static function xlsx(string $title, array $head, array $rows): string
    {
        $esc = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + ($i - 1) % 26) . $s;
            }
            return $s;
        };
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $all = array_merge([$head], $rows);
        foreach ($all as $ri => $row) {
            $sheet .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($row) as $ci => $c) {
                $ref = $col($ci) . ($ri + 1);
                if (is_array($c)) {
                    $sheet .= '<c r="' . $ref . '" s="2"><v>' . number_format($c['m'] / 100, 2, '.', '') . '</v></c>';
                } elseif (is_int($c)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $c . '</v></c>';
                } else {
                    $s = (string)($c ?? '');
                    if ($s !== '' && strpbrk($s[0], "=+-@") !== false) {
                        $s = "'" . $s;
                    }
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"' . ($ri === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $esc($s) . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $dir = FB_APP_DIR . '/storage/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $tmp = tempnam(is_writable($dir) ? $dir : sys_get_temp_dir(), 'fbx');
        if ($tmp === false) {
            throw new ApiError('storage', 'Geçici dosya oluşturulamadı.', 500);
        }
        $z = new \ZipArchive();
        $z->open($tmp, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title) ?? 'Rapor', 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf/><xf fontId="1" applyFont="1"/><xf numFmtId="164" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $z->close();
        $data = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    /** @return array{0:string,1:array,2:array} */
    private static function table(string $name, array $q, int $tid): array
    {
        $kinds = ['income' => 'Gelir', 'expense' => 'Gider', 'collection' => 'Tahsilat', 'payment' => 'Ödeme', 'transfer' => 'Transfer', 'owner_in' => 'İşletmeye para koyma', 'owner_out' => 'İşletmeden para çekme', 'fx' => 'Döviz', 'card_payment' => 'Kart ödemesi', 'loan_payment' => 'Kredi taksiti', 'loan_proceeds' => 'Kredi kullandırım', 'adjustment' => 'Bakiye düzeltme'];
        switch ($name) {
            case 'transactions':
                [$w, $p] = LedgerCtl::filters($q, $tid);
                $rows = [];
                foreach (DB::all("SELECT t.txn_date,t.kind,t.amount_minor,t.vat_minor,t.currency,t.description,t.status,a.name an,c.name cn,ct.name ctn FROM transactions t JOIN accounts a ON a.id=t.account_id LEFT JOIN categories c ON c.id=t.category_id LEFT JOIN contacts ct ON ct.id=t.contact_id WHERE $w ORDER BY t.txn_date DESC,t.id DESC LIMIT 20000", $p) as $x) {
                    $sign = in_array($x['kind'], ['income', 'collection', 'loan_proceeds'], true) ? 1 : -1;
                    $rows[] = [$x['txn_date'], $kinds[$x['kind']] ?? $x['kind'], $x['an'], self::m($sign * (int)$x['amount_minor']), $x['currency'], self::m((int)$x['vat_minor']), $x['description'], $x['cn'], $x['ctn'], $x['status'] === 'voided' ? 'İptal' : 'Aktif'];
                }
                return ['İşlemler', ['Tarih', 'Tür', 'Hesap', 'Tutar', 'Para birimi', 'KDV', 'Açıklama', 'Kategori', 'Cari', 'Durum'], $rows];
            case 'accounts':
                $rows = array_map(fn($a) => [$a['workspace_name'], $a['name'], $a['kind'] === 'bank' ? 'Banka' : 'Kasa', $a['bank_name'], $a['iban'], $a['currency'], self::m($a['balance_minor']), $a['archived'] ? 'Arşiv' : 'Aktif'], Fin::accounts($tid));
                return ['Hesaplar', ['Alan', 'Hesap', 'Tür', 'Banka', 'IBAN', 'Para birimi', 'Bakiye', 'Durum'], $rows];
            default:
                break;
        }
        return self::table2($name, $q, $tid);
    }

    private static function table2(string $name, array $q, int $tid): array
    {
        switch ($name) {
            case 'entries-list':
            case 'entries':
                $rows = [];
                foreach (Fin::entriesOpen($tid) as $e) {
                    $rows[] = [$e['kind'] === 'receivable' ? 'Alacak' : 'Borç', $e['title'], $e['due_date'], $e['currency'], self::m((int)$e['amount_minor']), self::m((int)$e['paid_minor']), self::m((int)$e['remaining_minor'])];
                }
                return ['Alacak-Borç', ['Tür', 'Başlık', 'Vade', 'Para birimi', 'Tutar', 'Ödenen', 'Kalan'], $rows];
            case 'invoices':
                $rows = [];
                foreach (DB::all('SELECT i.kind,i.number,i.issue_date,i.due_date,i.currency,i.status,i.subtotal_minor,i.vat_minor,i.total_minor,c.name FROM invoices i JOIN contacts c ON c.id=i.contact_id WHERE i.tenant_id=? ORDER BY i.issue_date DESC LIMIT 20000', [$tid]) as $i) {
                    $rows[] = [$i['kind'] === 'sales' ? 'Satış' : 'Alış', $i['number'], $i['issue_date'], $i['due_date'], $i['name'], $i['currency'], self::m((int)$i['subtotal_minor']), self::m((int)$i['vat_minor']), self::m((int)$i['total_minor']), ['planned' => 'Planlı', 'issued' => 'Kesildi', 'cancelled' => 'İptal'][$i['status']]];
                }
                return ['Faturalar', ['Tür', 'No', 'Tarih', 'Vade', 'Cari', 'Para birimi', 'KDV hariç', 'KDV', 'Toplam', 'Durum'], $rows];
            case 'income-expense':
                $d = ReportCtl::incomeExpense($q, $tid);
                return ['Gelir-Gider', ['Ay', 'Para birimi', 'Gelir', 'Gider', 'Kâr'], array_map(fn($x) => [$x['month'], $x['currency'], self::m($x['income_minor']), self::m($x['expense_minor']), self::m($x['profit_minor'])], $d['rows'])];
            case 'vat':
                $d = Pl::vat($tid, ReportCtl::pf($q, 'business'));
                return ['KDV', ['Yön', 'Para birimi', 'Oran %', 'KDV hariç', 'KDV', 'Brüt', 'Belge'], array_map(fn($x) => [$x['side'] === 'output' ? 'Hesaplanan' : 'İndirilecek', $x['currency'], $x['rate_bp'] / 100, self::m($x['net_minor']), self::m($x['vat_minor']), self::m($x['gross_minor']), $x['docs']], $d['rows'])];
            case 'aging':
                $d = ReportCtl::aging($q, $tid);
                return ['Yaşlandırma', ['Cari', 'Para birimi', 'Vadesi gelmemiş', '1-30', '31-60', '61-90', '90+', 'Toplam'], array_map(fn($x) => [$x['contact'], $x['currency'], self::m($x['current']), self::m($x['d30']), self::m($x['d60']), self::m($x['d90']), self::m($x['d90p']), self::m($x['total_minor'])], $d['rows'])];
            case 'projects':
                $d = ReportCtl::projects($tid);
                return ['Proje kârlılığı', ['Proje', 'Para birimi', 'Sözleşme', 'Gelir', 'Gider', 'Kâr', 'Nakit net'], array_map(fn($x) => [$x['name'], $x['currency'], self::m($x['contract_minor']), self::m($x['revenue_minor']), self::m($x['cost_minor']), self::m($x['profit_minor']), self::m($x['cash_net_minor'])], $d['projects'])];
            case 'cashflow':
                $d = ReportCtl::cashflow($q, $tid);
                return ['Nakit akışı', ['Ay', 'Para birimi', 'Giriş', 'Çıkış', 'Net'], array_map(fn($x) => [$x['month'], $x['currency'], self::m($x['cash_in_minor']), self::m($x['cash_out_minor']), self::m($x['net_minor'])], $d['realized'])];
            case 'budget':
                $d = ReportCtl::budget($q, $tid);
                return ['Bütçe', ['Kategori', 'Para birimi', 'Bütçe', 'Gerçekleşen', 'Fark'], array_map(fn($x) => [$x['category'], $x['currency'], self::m($x['budget_minor']), self::m($x['actual_minor']), self::m($x['diff_minor'])], $d['rows'])];
        }
        throw new ApiError('not_found', 'Bilinmeyen rapor.', 404);
    }
}
