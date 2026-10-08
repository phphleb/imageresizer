<?php
/** Тест разбора PNG iCCP без GD/Imagick, чтобы проверка файла не зависела от PNG-декодера. */
require __DIR__ . '/_support.php';
require dirname(__DIR__) . '/SimpleImage.php';
use Phphleb\Imageresizer\SimpleImage;
use Phphleb\Imageresizer\PngIcc;

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
suiteAssert(PngIcc::embed($png, $icc) === $png,
    'Already correct iCCP is preserved byte-for-byte');
$srgb = $signature . $header . suitePngChunk('sRGB', "\x00") . $pixel . $end;
suiteAssert(suitePngIcc($srgb) === null, 'PNG sRGB marker is not mistaken for embedded ICC');
$missing = $signature . $header . $pixel . $end;
suiteAssert(suitePngIcc($missing) === null, 'Profileless PNG has no ICC data');
$invalid = $signature . $header . suitePngChunk('iCCP', "icc\x00\x01" . gzcompress($icc)) . $pixel . $end;
suiteAssert(suitePngIcc($invalid) === null, 'Unsupported PNG iCCP compression method is rejected');
suiteAssert(suitePngIcc(substr($png, 0, 45)) === null, 'Truncated PNG iCCP is rejected');
// Simulate the PNG encoder silently dropping ICC metadata.
$recovered = PngIcc::embed($missing, $icc);
suiteAssert(is_string($recovered) && suitePngIcc($recovered) === $icc,
    'Restore full ICC after a PNG encoder drops it');
suiteAssert(suitePngChunks($recovered)['IDAT'] === suitePngChunks($missing)['IDAT'],
    'ICC restoration does not re-encode the pixels');

// ImageMagick may replace a recognized sRGB ICC with a short sRGB marker.
$fromMarker = PngIcc::embed($srgb, $icc);
$markerChunks = suitePngChunks($fromMarker);
suiteAssert(suitePngIcc($fromMarker) === $icc && !isset($markerChunks['sRGB']),
    'Replace PNG sRGB marker with the actual ICC profile');
suiteAssert($markerChunks['IDAT'] === suitePngChunks($srgb)['IDAT'],
    'Replacing the sRGB marker does not change pixel data');

// An old or incorrect iCCP must be replaced, never duplicated.
$different = substr_replace($icc, 'TEST', 100, 4);
$old = $signature . $header . suitePngChunk('iCCP', "Old Profile\x00\x00" . gzcompress($different)) .
    suitePngChunk('tEXt', "Comment\x00keep-me") . $pixel . $end;
$replaced = PngIcc::embed($old, $icc);
suiteAssert(suitePngIcc($replaced) === $icc && substr_count($replaced, 'iCCP') === 1,
    'Replace an existing iCCP without duplicating it');
suiteAssert(suitePngChunks($replaced)['tEXt'] === "Comment\x00keep-me",
    'Unrelated PNG metadata is retained when embedding ICC');

// Verify all generated PNG chunk checksums and the decoder's compatibility.
foreach ([$recovered, $fromMarker, $replaced] as $candidate) {
    $position = 8;
    while ($position < strlen($candidate)) {
        $size = unpack('N', substr($candidate, $position, 4))[1];
        $name = substr($candidate, $position + 4, 4);
        $bytes = substr($candidate, $position + 8, $size);
        $expectedCrc = unpack('N', substr($candidate, $position + 8 + $size, 4))[1];
        suiteAssert($expectedCrc === (crc32($name . $bytes) & 0xffffffff),
            "PNG {$name} chunk has correct CRC");
        $position += $size + 12;
    }
    suiteAssert($position === strlen($candidate), 'PNG structure remains intact');
}

suiteAssert(PngIcc::embed('not a PNG', $icc) === false, 'Invalid PNG data is rejected');
suiteAssert(PngIcc::embed(substr($missing, 0, -8), $icc) === false,
    'PNG without IEND is rejected');
suiteAssert(PngIcc::embed($missing, 'invalid profile') === false,
    'Invalid ICC cannot be injected');

echo "DONE: PNG ICC chunk extraction and lossless restoration tests passed\n";
