<?php
// Denetim kaydı hash zincirini doğrular.
require __DIR__ . '/_boot.php';
$b = FB\Audit::verifyChain(10000000);
echo $b === null ? "Denetim zinciri sağlam.\n" : "UYARI: zincir $b numaralı kayıtta bozuk!\n";
exit($b === null ? 0 : 2);
