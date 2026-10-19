<?php
return [
    'env' => 'dev',
    'base_url' => 'http://127.0.0.1:18100',
    'db' => ['host' => '127.0.0.1', 'port' => 3307, 'name' => 'fbmuh_e2e', 'user' => 'root', 'pass' => ''],
    'app_key' => base64_encode(str_repeat('e', 32)),
    'pepper' => str_repeat('cd', 32),
    'require_admin_2fa' => true,
    'mail_from' => 'no-reply@e2e.local',
    'mail_transport' => 'log',
    'pow_bits' => 14,
    'test_captcha' => 'TESTAB',
    'session_idle' => 3600,
    'session_absolute' => 43200,
    'session_rotate' => 600,
    'max_sessions' => 8,
    'rate_api_ip' => 100000,
    'rate_api_user' => 100000,
];
