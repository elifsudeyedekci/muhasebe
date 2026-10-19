<?php
return [
    'env' => 'dev',
    'base_url' => 'http://127.0.0.1:18099',
    'db' => ['host' => '127.0.0.1', 'port' => 3307, 'name' => 'fbmuh_test', 'user' => 'root', 'pass' => ''],
    'app_key' => base64_encode(str_repeat('k', 32)),
    'pepper' => str_repeat('ab', 32),
    'require_admin_2fa' => true,
    'mail_from' => 'no-reply@test.local',
    'mail_transport' => 'log',
    'pow_bits' => 8,
    'test_captcha' => 'TESTAB',
    'session_idle' => 3600,
    'session_absolute' => 43200,
    'session_rotate' => 2,
    'max_sessions' => 8,
    'rate_api_ip' => 100000,
    'rate_api_user' => 100000,
];
