<?php
/**
 * Интеграционные тесты: реальные GD и/или Imagick, когда расширения установлены.
 * Запуск: php tests/integration.php
 */
require dirname(__DIR__) . '/SimpleImage.php';

use Phphleb\Imageresizer\SimpleImage;
use Phphleb\Imageresizer\ImageError;

function verify($value, $description)
{
    if (!$value) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
    echo "PASS: {$description}\n";
}

function pngChunk($type, $data)
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

$dir = sys_get_temp_dir() . '/imageresizer-test-' . uniqid('', true);
verify(mkdir($dir, 0700), 'create test directory');
$source = $dir . '/source.png';
// Минимальный PNG 1x1, без дополнительных библиотек.
$raw = "\x00\xff\x00\x00";
$png = "\x89PNG\r\n\x1a\n"
    . pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
    . pngChunk('IDAT', gzcompress($raw))
    . pngChunk('IEND', '');
file_put_contents($source, $png);
verify(getimagesize($source)[0] === 1, 'source fixture is a valid PNG');

$tested = 0;
foreach (array(SimpleImage::PROCESSOR_GD, SimpleImage::PROCESSOR_IMAGICK) as $backend) {
    if (!extension_loaded($backend)) {
        echo "SKIP: {$backend} extension is missing\n";
        continue;
    }
    $tested++;
    $image = new SimpleImage();
    verify($image->setProcessorVersion($backend), "select {$backend}");
    verify($image->load($source), "{$backend} load PNG");
    verify($image->getWidth() === 1 && $image->getHeight() === 1, "{$backend} original dimensions");
    $image->resizeToWidth(8);
    verify($image->getWidth() === 8 && $image->getHeight() === 8, "{$backend} proportional resizing");
    $image->cropBySelectedRegion(5, 6, 2, 1);
    verify($image->getWidth() === 5 && $image->getHeight() === 6, "{$backend} cropping");
    $image->resizeInCenter(10, 7);
    verify($image->getWidth() === 10 && $image->getHeight() === 7, "{$backend} cover resizing");
    $image->resizeAllInCenter(9, 11, '#ffffff');
    verify($image->getWidth() === 9 && $image->getHeight() === 11, "{$backend} contain resizing");
    $out = $dir . '/' . $backend . '.png';
    verify($image->save($out, 'png'), "{$backend} save PNG");
    $info = getimagesize($out);
    verify($info && $info[0] === 9 && $info[1] === 11 && $info[2] === IMAGETYPE_PNG, "{$backend} output PNG decodes");
    ob_start();
    $result = $image->output('png');
    $blob = ob_get_clean();
    verify($result === true && substr($blob, 0, 8) === "\x89PNG\r\n\x1a\n", "{$backend} output stream");
    if ($backend === SimpleImage::PROCESSOR_GD) {
        verify($image->setProfile(SimpleImage::PROFILE_SRGB) === false, 'GD rejects ICC embedding');
        verify($image->getError()->getCode() === ImageError::PROFILE_UNSUPPORTED, 'GD ICC error code');
    } else {
        $icc = file_get_contents(SimpleImage::PROFILE_SRGB);
        verify($image->getImage()->getImageProfiles('icc', true)['icc'] === $icc, 'Imagick preserves ICC through transformations');
        verify($image->setProfile(SimpleImage::PROFILE_SRGB, true), 'Imagick replaces ICC');
        verify($image->convertToProfile(SimpleImage::PROFILE_SRGB), 'Imagick converts ICC profile');
        verify($image->getImage()->getImageProfiles('icc', true)['icc'] === $icc, 'Imagick ICC output is correct');
        verify($image->save($dir . '/imagick-converted.png', 'png'), 'Imagick saves converted PNG');
    }
}
foreach (glob($dir . '/*') as $file) unlink($file);
rmdir($dir);
echo $tested ? "DONE: real processor tests finished\n" : "SKIP: no GD or Imagick extension; run on PHP with image extensions for integration coverage\n";
