<?php
declare(strict_types=1);

use FB\Captcha;
use FB\DB;
use FB\Totp;

$GLOBALS['T'] = ['pass' => 0, 'fail' => 0, 'fails' => []];

function check(bool $ok, string $name, mixed $detail = null): void
{
    if ($ok) {
        $GLOBALS['T']['pass']++;
        echo "  ok   $name\n";
        return;
    }
    $GLOBALS['T']['fail']++;
    $d = $detail === null ? '' : ' → ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE));
    $GLOBALS['T']['fails'][] = $name . $d;
    echo "  FAIL $name$d\n";
}

function eq(mixed $got, mixed $want, string $name): void
{
    check($got === $want, $name, ['got' => $got, 'want' => $want]);
}

function section(string $s): void
{
    echo "\n== $s ==\n";
}

final class Client
{
    public string $jar;
    public ?string $csrf = null;
    public array $lastHeaders = [];
    public string $origin = 'http://127.0.0.1:18099';

    public function __construct(public string $base = 'http://127.0.0.1:18099')
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'fbj');
    }

    public function req(string $method, string $path, ?array $body = null, array $headers = [], bool $csrf = true, ?string $raw = null, ?string $ctype = null): array
    {
        $ch = curl_init($this->base . '/api' . $path);
        $h = ['Accept: application/json', 'Origin: ' . $this->origin, 'User-Agent: FBTest/1.0'];
        if ($csrf && $this->csrf) {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60];
        if ($raw !== null) {
            $opts[CURLOPT_POSTFIELDS] = $raw;
            if ($ctype) {
                $h[] = 'Content-Type: ' . $ctype;
            }
        } elseif ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            $h[] = 'Content-Type: application/json';
        }
        $this->lastHeaders = [];
        $opts[CURLOPT_HTTPHEADER] = $h;
        $opts[CURLOPT_HEADERFUNCTION] = function ($c, $line) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) {
                $this->lastHeaders[strtolower(trim($p[0]))][] = trim($p[1]);
            }
            return strlen($line);
        };
        curl_setopt_array($ch, $opts);
        $out = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($out, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $out, 'h' => $this->lastHeaders, 'ok' => is_array($json) && ($json['ok'] ?? false)];
    }

    public function get(string $p, array $q = []): array
    {
        return $this->req('GET', $p . ($q ? '?' . http_build_query($q) : ''));
    }

    public function post(string $p, array $b = [], array $h = []): array
    {
        return $this->req('POST', $p, $b, $h);
    }

    public function upload(string $path, array $fields, string $file, string $mime, string $name): array
    {
        $ch = curl_init($this->base . '/api' . $path);
        $fields['file'] = new CURLFile($file, $mime, $name);
        $h = ['Accept: application/json', 'Origin: ' . $this->origin, 'User-Agent: FBTest/1.0', 'X-CSRF-Token: ' . $this->csrf];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HTTPHEADER => $h]);
        $out = (string)curl_exec($ch);
        $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode($out, true);
        return ['status' => $st, 'json' => is_array($j) ? $j : null, 'raw' => $out, 'ok' => is_array($j) && ($j['ok'] ?? false)];
    }

    public function captcha(): array
    {
        $c = $this->req('GET', '/auth/captcha');
        $j = $c['json'];
        $n = 0;
        while (!Captcha::powOk($j['pow']['seed'], (string)$n, $j['pow']['bits'])) {
            $n++;
        }
        return ['captcha_id' => $j['id'], 'captcha_answer' => 'TESTAB', 'pow_nonce' => (string)$n];
    }

    public function login(string $email, string $pass, ?string $totpSecret = null): array
    {
        $r = $this->req('POST', '/auth/login', ['email' => $email, 'password' => $pass] + $this->captcha(), [], false);
        if ($r['ok'] && !empty($r['json']['need_2fa'])) {
            $code = totpFor((string)$totpSecret, $email);
            $r = $this->req('POST', '/auth/2fa', ['ticket' => $r['json']['ticket'], 'code' => $code], [], false);
        }
        if ($r['ok'] && isset($r['json']['csrf'])) {
            $this->csrf = $r['json']['csrf'];
        }
        return $r;
    }

    public function cookie(string $name = 'fbsid'): ?string
    {
        foreach (file($this->jar) ?: [] as $l) {
            $l = ltrim($l, '#HttpOnly_');
            $p = explode("\t", trim($l));
            if (count($p) >= 7 && $p[5] === $name) {
                return $p[6];
            }
        }
        return null;
    }

    public function setCookie(string $v, string $name = 'fbsid'): void
    {
        file_put_contents($this->jar, "# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t0\t$name\t$v\n");
    }
}

function resetDb(): void
{
    $pdo = DB::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $t) {
        $pdo->exec('DROP TABLE `' . $t[0] . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

/** Para: "1234.56" TL → küçük birim */
function tl(float|int|string $v): int
{
    return (int)round(((float)$v) * 100);
}

/** TOTP yeniden kullanım koruması (replay) nedeniyle: son kullanılan adımdan büyük, pencere içindeki ilk adımın kodu. */
function totpFor(string $secret, string $email): string
{
    for ($i = 0; $i < 40; $i++) {
        $last = (int)DB::val('SELECT totp_last_step FROM users WHERE email=?', [$email]);
        $cur = intdiv(time(), 30);
        $step = max($last + 1, $cur - 1);
        if ($step <= $cur + 1) {
            return Totp::code($secret, $step);
        }
        sleep(3);
    }
    return '000000';
}
