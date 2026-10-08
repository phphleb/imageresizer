<?php
/** Без GD, Imagick и EXIF: проверка всех ориентаций TIFF, включая повреждённые. */
require __DIR__ . '/_support.php';
require dirname(__DIR__) . '/ExifOrientation.php';
use Phphleb\Imageresizer\ExifOrientation;
$dir = suiteDirectory();
for ($orientation = 1; $orientation <= 8; $orientation++) {
    $exif = suiteExif($orientation);
    suiteAssert(ExifOrientation::fromProfile($exif) === $orientation, "Read EXIF orientation {$orientation}");
    $normalized = ExifOrientation::normalizeProfile($exif);
    suiteAssert(ExifOrientation::fromProfile($normalized) === 1, "Normalize EXIF orientation {$orientation}");
    suiteAssert(strpos($normalized, 'SensitiveCamera') !== false && strpos($normalized, "N\0\0\0") !== false,
        "EXIF normalizing preserves other metadata {$orientation}");
    $file = $dir . '/orientation-' . $orientation . '.jpg';
    file_put_contents($file, "\xff\xd8\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . "\xff\xd9");
    suiteAssert(ExifOrientation::fromFile($file) === $orientation, "Read JPEG APP1 orientation {$orientation}");
}
$invalid = ["", 'broken', "Exif\0\0" . str_repeat('?', 64), "Exif\0\0II\0\0"];
foreach ($invalid as $data) {
    suiteAssert(ExifOrientation::normalizeProfile($data) === $data, 'Damaged EXIF is left unchanged');
}
// Big-endian TIFF orientation must be normalized as well.
$big = 'MM' . pack('nNn', 42, 8, 1) . pack('nnNnnN', 0x0112, 3, 1, 8, 0, 0);
suiteAssert(ExifOrientation::fromProfile($big) === 8, 'Read big endian EXIF');
suiteAssert(ExifOrientation::fromProfile(ExifOrientation::normalizeProfile($big)) === 1,
    'Normalize big endian EXIF');
echo "DONE: binary EXIF parsing and orientation checks\n";
