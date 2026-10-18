<?php
declare(strict_types=1);

namespace FB;

/** Belge/fiş yükleme ve yetkili indirme. Dosyalar web kökü dışında saklanır; erişim yalnızca bu uç noktalardan, oturum + sahiplik denetimiyle. */
final class FileCtl
{
    private const ENTITIES = ['transaction' => 'transactions', 'invoice' => 'invoices', 'entry' => 'entries', 'contact' => 'contacts', 'project' => 'projects', 'card_purchase' => 'card_purchases'];
    private const MIMES = [
        'application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp'], 'image/heic' => ['heic'],
        'text/plain' => ['csv', 'txt'], 'text/csv' => ['csv'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'], 'application/zip' => ['xlsx'],
    ];
    private const MAX = 10 * 1024 * 1024;
    private const QUOTA = 500 * 1024 * 1024;

    public static function routes(Router $r): void
    {
        $t = ['auth' => 'tenant'];
        $r->add('POST', '/documents', fn($q, $p, $tid) => self::upload($q, $tid), $t);
        $r->add('GET', '/documents', fn($q, $p, $tid) => self::list($q, $tid), $t);
        $r->add('GET', '/documents/{id}/download', fn($q, $p, $tid) => self::download((int)$p['id'], $tid), $t);
        $r->add('DELETE', '/documents/{id}', fn($q, $p, $tid) => self::delete((int)$p['id'], $tid), $t);
    }

    public static function dir(int $tid, string $stored): string
    {
        return FB_APP_DIR . '/storage/uploads/' . $tid . '/' . substr($stored, 0, 2);
    }

    private static function upload(Req $r, int $tid): never
    {
        $entity = (string)V::enum($r->body, 'entity', array_keys(self::ENTITIES));
        $eid = (int)V::id($r->body['entity_id'] ?? null, 'entity_id');
        if (!DB::val('SELECT id FROM `' . self::ENTITIES[$entity] . '` WHERE id=? AND tenant_id=?', [$eid, $tid])) {
            throw new ApiError('not_found', 'Bağlanacak kayıt bulunamadı.', 404, 'entity_id');
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']) && !(getenv('FB_TEST') && is_file((string)$f['tmp_name']))) {
            throw new ApiError('upload_failed', 'Dosya yüklenemedi.', 422, 'file');
        }
        $size = (int)$f['size'];
        if ($size <= 0 || $size > self::MAX) {
            throw new ApiError('too_large', 'Dosya en fazla 10 MB olabilir.', 413, 'file');
        }
        $used = (int)DB::val('SELECT COALESCE(SUM(size_bytes),0) FROM documents WHERE tenant_id=? AND deleted_at IS NULL', [$tid]);
        if ($used + $size > self::QUOTA) {
            throw new ApiError('quota', 'Depolama kotası doldu.', 413, 'file');
        }
        $fi = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file((string)$f['tmp_name']);
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!isset(self::MIMES[$mime]) || !in_array($ext, self::MIMES[$mime], true)) {
            throw new ApiError('bad_type', 'Dosya türü desteklenmiyor (PDF, JPG, PNG, WEBP, CSV, XLSX).', 415, 'file');
        }
        if ($mime === 'application/zip' && !str_contains((string)@file_get_contents((string)$f['tmp_name'], false, null, 0, 4096), 'xl/')) {
            throw new ApiError('bad_type', 'Dosya türü desteklenmiyor.', 415, 'file');
        }
        $stored = bin2hex(random_bytes(32));
        $dir = self::dir($tid, $stored);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new ApiError('storage', 'Depolama alanı kullanılamıyor.', 500);
        }
        $dest = $dir . '/' . $stored;
        $ok = is_uploaded_file((string)$f['tmp_name']) ? move_uploaded_file((string)$f['tmp_name'], $dest) : copy((string)$f['tmp_name'], $dest);
        if (!$ok) {
            throw new ApiError('storage', 'Dosya kaydedilemedi.', 500);
        }
        @chmod($dest, 0640);
        $name = preg_replace('/[^\p{L}\p{N}._ ()-]/u', '_', basename((string)$f['name'])) ?? 'dosya';
        $id = DB::insert('documents', [
            'tenant_id' => $tid, 'entity' => $entity, 'entity_id' => $eid, 'orig_name' => mb_substr($name, 0, 190), 'stored_name' => $stored, 'mime' => $mime,
            'size_bytes' => $size, 'sha256' => hash_file('sha256', $dest), 'created_by' => Auth::$user['id'], 'created_at' => Util::now(),
        ]);
        Audit::log('data', 'document_uploaded', ['tenant' => $tid, 'entity' => $entity, 'id' => $eid, 'details' => ['doc' => $id, 'size' => $size]]);
        Res::ok(['id' => $id], 201);
    }

    private static function list(Req $r, int $tid): never
    {
        $entity = (string)V::enum($r->query, 'entity', array_keys(self::ENTITIES));
        $eid = (int)V::id($r->query['entity_id'] ?? null, 'entity_id');
        Res::ok(['documents' => DB::all('SELECT id,orig_name,mime,size_bytes,created_at FROM documents WHERE tenant_id=? AND entity=? AND entity_id=? AND deleted_at IS NULL ORDER BY id DESC', [$tid, $entity, $eid])]);
    }

    private static function download(int $id, int $tid): never
    {
        $d = DB::one('SELECT * FROM documents WHERE id=? AND tenant_id=? AND deleted_at IS NULL', [$id, $tid]);
        if (!$d) {
            throw new ApiError('not_found', 'Belge bulunamadı.', 404);
        }
        $path = self::dir($tid, $d['stored_name']) . '/' . $d['stored_name'];
        if (!is_file($path)) {
            throw new ApiError('not_found', 'Belge dosyası bulunamadı.', 404);
        }
        if (Auth::$user['role'] === 'admin') {
            Audit::log('access', 'admin_download_document', ['tenant' => $tid, 'entity' => 'document', 'id' => $id]);
        }
        Res::securityHeaders();
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Type: ' . $d['mime']);
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($d['orig_name']));
        readfile($path);
        exit;
    }

    private static function delete(int $id, int $tid): never
    {
        $n = DB::exec('UPDATE documents SET deleted_at=? WHERE id=? AND tenant_id=? AND deleted_at IS NULL', [Util::now(), $id, $tid]);
        if (!$n) {
            throw new ApiError('not_found', 'Belge bulunamadı.', 404);
        }
        Audit::log('data', 'document_deleted', ['tenant' => $tid, 'entity' => 'document', 'id' => $id]);
        Res::ok();
    }
}
