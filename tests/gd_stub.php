<?php
/**
 * Проверяет работу обёртки GD с имитацией расширения (без проверки реального ресемплинга).
 */
namespace Phphleb\Imageresizer {
    function extension_loaded($name) { return $name === 'gd'; }
}
namespace {
    if (\extension_loaded('gd')) {
        echo "SKIP: GD extension is available; use tests/integration.php instead\n";
        exit(0);
    }
    class FakeGdImage
    {
        public $width;
        public $height;
        public function __construct($width, $height) { $this->width = $width; $this->height = $height; }
    }
    function imagecreatefrompng($path)
    {
        $info = @getimagesize($path);
        return $info ? new FakeGdImage($info[0], $info[1]) : false;
    }
    function imagecreatetruecolor($w, $h) { return new FakeGdImage($w, $h); }
    function imagealphablending($image, $setting) { return true; }
    function imagesavealpha($image, $setting) { return true; }
    function imagecolorallocate($image, $r, $g, $b) { return 1; }
    function imagecolorallocatealpha($image, $r, $g, $b, $a) { return 2; }
    function imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color) { return true; }
    function imagecopyresampled($dest, $src, $dx, $dy, $sx, $sy, $dw, $dh, $sw, $sh) { return true; }
    function imagesx($image) { return $image->width; }
    function imagesy($image) { return $image->height; }
    function pngStubChunk($tag, $payload)
    {
        return pack('N', strlen($payload)) . $tag . $payload . pack('N', crc32($tag . $payload));
    }
    function pngStubBytes($w, $h)
    {
        $raw = str_repeat("\x00" . str_repeat("\xff\x00\x00", $w), $h);
        return "\x89PNG\r\n\x1a\n" . pngStubChunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            . pngStubChunk('IDAT', gzcompress($raw)) . pngStubChunk('IEND', '');
    }
    function imagepng($image, $path = null)
    {
        $bytes = pngStubBytes($image->width, $image->height);
        if ($path === null) { echo $bytes; return true; }
        return file_put_contents($path, $bytes) !== false;
    }

    require dirname(__DIR__) . '/SimpleImage.php';
    use Phphleb\Imageresizer\SimpleImage;
    use Phphleb\Imageresizer\ImageError;

    function verifyStub($ok, $description)
    {
        if (!$ok) { fwrite(STDERR, "FAIL: {$description}\n"); exit(1); }
        echo "PASS: {$description}\n";
    }
    $dir = sys_get_temp_dir() . '/imageresizer-gd-mock-' . uniqid('', true);
    mkdir($dir, 0700);
    $source = $dir . '/source.png';
    file_put_contents($source, pngStubBytes(2, 3));
    $image = new SimpleImage();
    verifyStub($image->load($source), 'AUTO selects fake GD without Imagick');
    verifyStub($image->getProcessorVersion() === SimpleImage::PROCESSOR_GD, 'GD backend detected');
    verifyStub($image->getWidth() === 2 && $image->getHeight() === 3, 'GD original dimensions');
    $image->resizeToWidth(6);
    verifyStub($image->getWidth() === 6 && $image->getHeight() === 9, 'GD proportional resize');
    $image->resizeInCenter(7, 8);
    verifyStub($image->getWidth() === 7 && $image->getHeight() === 8, 'GD crop-to-cover');
    $image->cropBySelectedRegion(4, 5, 0, 1);
    verifyStub($image->getWidth() === 4 && $image->getHeight() === 5, 'GD crop');
    $image->resizeAllInCenter(10, 11, '#0f0');
    verifyStub($image->getWidth() === 10 && $image->getHeight() === 11, 'GD contain');
    $out = $dir . '/out.png';
    verifyStub($image->save($out, 'png'), 'GD saving PNG');
    $size = getimagesize($out);
    verifyStub($size && $size[0] === 10 && $size[1] === 11, 'GD output PNG dimensions');
    ob_start(); $worked = $image->output('png'); $png = ob_get_clean();
    verifyStub($worked && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n", 'GD output stream');
    verifyStub($image->setProfile(SimpleImage::PROFILE_SRGB) === false, 'GD reports unsupported ICC');
    verifyStub($image->getError()->getCode() === ImageError::PROFILE_UNSUPPORTED, 'GD profile error code');
    verifyStub($image->convertToProfile(SimpleImage::PROFILE_SRGB) === false, 'GD cannot convert ICC');
    verifyStub(!preg_match('/[А-ЯЁа-яё]/u', $image->getError()->getMessage()), 'GD error message is English');
    verifyStub($image->save($out, 'png') && $image->getError() === null, 'successful operation clears error');
    unlink($source); unlink($out); rmdir($dir);
    echo "DONE: GD wrapper mock tests passed (not a real GD integration test)\n";
}
