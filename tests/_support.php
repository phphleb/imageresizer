<?php
/** Общие помощники настоящих интеграционных тестов. Не требуют PHPUnit. */

function suiteAssert($value, string $description): void
{
    if (!$value) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
    echo "PASS: {$description}\n";
}

function suiteDirectory(): string
{
    $path = sys_get_temp_dir() . '/imageresizer-suite-' . bin2hex(random_bytes(7));
    suiteAssert(mkdir($path, 0700), 'Create isolated temporary fixture directory');
    register_shutdown_function(function () use ($path) {
        foreach (glob($path . '/*') ?: [] as $file) if (is_file($file)) @unlink($file);
        @rmdir($path);
    });
    return $path;
}

function suitePngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/** Возвращает секции PNG по типам, чтобы проверять их без особенностей декодера Imagick. */
function suitePngChunks(string $bytes): array
{
    if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") return [];
    $chunks = [];
    $length = strlen($bytes);
    $offset = 8;
    while ($offset + 12 <= $length) {
        $size = unpack('N', substr($bytes, $offset, 4))[1];
        if ($size > $length - $offset - 12) return [];
        $type = substr($bytes, $offset + 4, 4);
        $chunks[$type] = substr($bytes, $offset + 8, $size);
        $offset += 12 + $size;
        if ($type === 'IEND') break;
    }
    return $chunks;
}

/** Читает байты ICC из настоящего PNG iCCP, а не из метаданных Imagick. */
function suitePngIcc(string $bytes): ?string
{
    $chunks = suitePngChunks($bytes);
    if (!isset($chunks['iCCP'])) return null;
    $chunk = $chunks['iCCP'];
    $separator = strpos($chunk, "\x00");
    if ($separator === false || !isset($chunk[$separator + 1]) ||
        ord($chunk[$separator + 1]) !== 0) return null;
    $icc = @gzuncompress(substr($chunk, $separator + 2));
    return is_string($icc) ? $icc : null;
}

/** PNG RGBA без внешних инструментов; четыре цветных квадранта и прозрачный угол. */
function suitePng(string $path, int $w = 60, int $h = 40, bool $transparent = false): void
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\x00";
        for ($x = 0; $x < $w; $x++) {
            $top = $y < $h / 2;
            $left = $x < $w / 2;
            if ($top && $left) $rgb = [255, 0, 0];
            elseif ($top) $rgb = [0, 255, 0];
            elseif ($left) $rgb = [0, 0, 255];
            else $rgb = [255, 255, 0];
            $alpha = $transparent && $x < $w / 4 && $y < $h / 4 ? 0 : 255;
            $raw .= pack('C4', $rgb[0], $rgb[1], $rgb[2], $alpha);
        }
    }
    $bytes = "\x89PNG\r\n\x1a\n"
        . suitePngChunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0))
        . suitePngChunk('IDAT', gzcompress($raw, 6))
        . suitePngChunk('IEND', '');
    suiteAssert(file_put_contents($path, $bytes) === strlen($bytes), 'Generate fixture PNG');
}

/** Профиль EXIF: Orientation, чувствительное название камеры и GPSLatitudeRef. */
function suiteExif(int $orientation): string
{
    $make = "SensitiveCamera\0";
    $makeOffset = 8 + 2 + 3 * 12 + 4;
    $gpsOffset = $makeOffset + strlen($make);
    $tiff = 'II' . pack('vV', 42, 8);
    $tiff .= pack('v', 3);
    $tiff .= pack('vvVV', 0x010f, 2, strlen($make), $makeOffset);
    $tiff .= pack('vvVvv', 0x0112, 3, 1, $orientation, 0);
    $tiff .= pack('vvVV', 0x8825, 4, 1, $gpsOffset);
    $tiff .= pack('V', 0) . $make;
    $tiff .= pack('v', 1) . pack('vvV', 0x0001, 2, 2) . "N\0\0\0" . pack('V', 0);
    return "Exif\0\0" . $tiff;
}

function suiteJpegWithMetadata(string $source, string $target, int $orientation): void
{
    $jpeg = file_get_contents($source);
    suiteAssert(substr($jpeg, 0, 2) === "\xff\xd8", 'JPEG fixture has SOI marker');
    $exif = suiteExif($orientation);
    $xmp = "http://ns.adobe.com/xap/1.0/\0" .
        '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' .
        '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:creator="PrivateXMPMarker"/></rdf:RDF></x:xmpmeta>';
    $app1 = "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif;
    $app1 .= "\xff\xe1" . pack('n', strlen($xmp) + 2) . $xmp;
    suiteAssert(file_put_contents($target, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2)) !== false,
        "Inject EXIF and XMP fixture (orientation {$orientation})");
}

/** Сделать JPEG выбранным реальным движком, без зависимости от другого расширения. */
function suiteJpegFromPng(string $png, string $jpeg, string $backend): void
{
    if ($backend === 'gd') {
        $im = imagecreatefrompng($png);
        suiteAssert($im !== false && imagejpeg($im, $jpeg, 100), 'Create JPEG using GD');
    } else {
        $im = new \Imagick($png);
        suiteAssert($im->setImageFormat('jpeg') !== false, 'Configure Imagick JPEG');
        suiteAssert($im->setImageCompressionQuality(100) !== false && $im->writeImage($jpeg) !== false,
            'Create JPEG using Imagick');
    }
}

/** Чтение среднего RGB в пикселях с небольшим отступом от границы. */
function suitePixelRgb($image, int $x, int $y, string $backend): array
{
    if ($backend === 'gd') {
        $pixel = imagecolorat($image, $x, $y);
        return [(int) (($pixel >> 16) & 255), (int) (($pixel >> 8) & 255), (int) ($pixel & 255)];
    }
    $color = $image->getImagePixelColor($x, $y)->getColor();
    return [(int) $color['r'], (int) $color['g'], (int) $color['b']];
}

function suiteNearestColor(array $rgb): string
{
    $palette = ['R' => [255, 0, 0], 'G' => [0, 255, 0], 'B' => [0, 0, 255], 'Y' => [255, 255, 0]];
    $best = '';
    $distance = PHP_INT_MAX;
    foreach ($palette as $name => $color) {
        $d = 0;
        foreach ([0, 1, 2] as $index) $d += ($rgb[$index] - $color[$index]) ** 2;
        if ($d < $distance) { $distance = $d; $best = $name; }
    }
    return $best;
}

function suiteQuadrants($image, int $w, int $h, string $backend): string
{
    $out = '';
    foreach ([[.25, .25], [.75, .25], [.25, .75], [.75, .75]] as $coord) {
        $out .= suiteNearestColor(suitePixelRgb($image, (int) floor($w * $coord[0]), (int) floor($h * $coord[1]), $backend));
    }
    return $out;
}
