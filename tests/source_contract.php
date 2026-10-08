<?php
/** Статические контракты ошибок: только английский в строковых литералах исходников. */
require __DIR__ . '/_support.php';
$files = glob(dirname(__DIR__) . '/*.php');
suiteAssert(count($files) >= 6, 'PHP source files detected');
foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    foreach ($tokens as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            if (preg_match('/[А-ЯЁа-яё]/u', $token[1])) {
                fwrite(STDERR, 'FAIL: Russian runtime string in ' . basename($file) . ':' . $token[2] . "\n");
                exit(1);
            }
        }
    }
    echo 'PASS: English runtime strings in ' . basename($file) . "\n";
}
echo "DONE: no Russian runtime string literals in PHP source\n";
