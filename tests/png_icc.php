<?php
/** Тест разбора PNG iCCP без GD/Imagick, чтобы проверка файла не зависела от PNG-декодера. */
require __DIR__ . '/_support.php';
require dirname(__DIR__) . '/SimpleImage.php';
use Phphleb\Imageresizer\SimpleImage;

$icc = file_get_contents(SimpleImage::PROFILE_SRGB);
suiteAssert(is_string($icc) && strlen($icc) > 100, 'Bundled ICC available for PNG test');
$signature = "\x89PNG\r\n\x1a\n";
$header = suitePngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0));
$pixel = suitePngChunk('IDAT', gzcompress("\x00\xff\x00\x00\xff"));
$end = suitePngChunk('IEND', '');
$profile = suitePngChunk('iCCP', "sRGB IEC61966-2.1\x00\x00" . gzcompress($icc));
$png = $signature . $header . $profile . $pixel . $end;
suiteAssert(suitePngIcc($png) === $icc, 'Extract exact ICC bytes from actual PNG iCCP');
$chunks = suitePngChunks($png);
suiteAssert(isset($chunks['iCCP']), 'PNG iCCP chunk detected');
$srgb = $signature . $header . suitePngChunk('sRGB', "\x00") . $pixel . $end;
suiteAssert(suitePngIcc($srgb) === null, 'PNG sRGB marker is not mistaken for embedded ICC');
$missing = $signature . $header . $pixel . $end;
suiteAssert(suitePngIcc($missing) === null, 'Profileless PNG has no ICC data');
$invalid = $signature . $header . suitePngChunk('iCCP', "icc\x00\x01" . gzcompress($icc)) . $pixel . $end;
suiteAssert(suitePngIcc($invalid) === null, 'Unsupported PNG iCCP compression method is rejected');
suiteAssert(suitePngIcc(substr($png, 0, 45)) === null, 'Truncated PNG iCCP is rejected');
echo "DONE: PNG ICC chunk extraction tests passed\n";
