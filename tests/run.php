<?php
/** Запуск полного набора: php tests/run.php imagick | gd */
$backend = $argv[1] ?? '';
if ($backend !== 'imagick' && $backend !== 'gd') {
    fwrite(STDERR, "Usage: php tests/run.php imagick|gd\n");
    exit(2);
}
$php = escapeshellarg(PHP_BINARY);
$common = ['smoke.php', 'bc_review.php', 'icc_profile.php', 'png_icc.php', 'exif_orientation.php', 'source_contract.php'];
$mocks = ['imagick_stub.php', 'imagick_orientation_legacy_stub.php', 'gd_stub.php', 'fallback_stub.php'];
foreach ($common as $test) {
    $command = $php . ' ' . escapeshellarg(__DIR__ . '/' . $test);
    echo "\n=== {$test} ===\n";
    passthru($command, $result);
    if ($result !== 0) exit($result);
}
foreach ($mocks as $test) {
    // Независимо от установленных расширений тестируем также отсутствие GD/Imagick.
    $command = $php . ' -n ' . escapeshellarg(__DIR__ . '/' . $test);
    echo "\n=== {$test} [simulated, no PHP extensions] ===\n";
    passthru($command, $result);
    if ($result !== 0) exit($result);
}
$command = $php . ' ' . escapeshellarg(__DIR__ . '/real_backend.php') . ' ' . escapeshellarg($backend);
echo "\n=== real_backend.php {$backend} [real extension required] ===\n";
passthru($command, $result);
if ($result !== 0) exit($result);
echo "\nALL TESTS PASSED: {$backend}\n";
