<?php
/**
 * Проверяет логику обёртки Imagick без установленного расширения.
 * Это имитация методов, НЕ тест реальной обработки цветов.
 */
namespace Phphleb\Imageresizer {
    function extension_loaded($name) { return $name === 'imagick'; }
}
namespace {
    if (\extension_loaded('imagick')) {
        echo "SKIP: Imagick extension is available; use tests/integration.php instead\n";
        exit(0);
    }
    class ImagickPixel
    {
        public function __construct($color) { }
    }
    class Imagick
    {
        const FILTER_LANCZOS = 1;
        const COMPOSITE_OVER = 1;
        public $profiles = array();
        public $conversions = 0;
        public $width = 0;
        public $height = 0;
        public $pixels = 'unset';
        public $format = 'png';

        public function readImage($file)
        {
            $info = @getimagesize($file);
            if (!$info) throw new \RuntimeException('Invalid input image');
            $this->width = $info[0];
            $this->height = $info[1];
            $this->pixels = 'loaded';
            if (strpos($file, 'profiled') !== false) $this->profiles['icc'] = 'PREEXISTING_PROFILE';
            return true;
        }
        public function getNumberImages() { return 1; }
        public function getImageWidth() { return $this->width; }
        public function getImageHeight() { return $this->height; }
        public function getImageDepth() { return 8; }
        public function setImageDepth($depth) { return true; }
        public function resizeImage($w, $h, $filter, $blur, $bestfit)
        {
            $this->width = $w;
            $this->height = $h;
            $this->pixels .= ':resized';
            return true;
        }
        public function newImage($w, $h, $color, $format)
        {
            $this->width = $w;
            $this->height = $h;
            $this->pixels = 'canvas';
            return true;
        }
        public function compositeImage($img, $mode, $x, $y)
        {
            $this->pixels .= ':composited';
            return true;
        }
        public function getImageProfiles($pattern = '*', $include_values = true)
        {
            $profiles = $this->profiles;
            if ($pattern !== '*') $profiles = array_intersect_key($profiles, array($pattern => 1));
            return $include_values ? $profiles : array_keys($profiles);
        }
        public function setImageProfile($name, $data)
        {
            $this->profiles[$name] = $data;
            return true;
        }
        public function profileImage($name, $data)
        {
            $this->conversions++;
            $this->profiles[$name] = $data;
            $this->pixels .= ':colorconverted';
            return true;
        }
        public function setImageFormat($format) { $this->format = $format; return true; }
        public function setImageCompressionQuality($quality) { return true; }
        public function writeImage($path) { return file_put_contents($path, 'stub-image-data') !== false; }
        public function getImageBlob() { return 'stub-image-data'; }
    }

    require dirname(__DIR__) . '/SimpleImage.php';
    use Phphleb\Imageresizer\SimpleImage;
    use Phphleb\Imageresizer\ImageError;

    function checkStub($passed, $message)
    {
        if (!$passed) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
        echo "PASS: {$message}\n";
    }
    $dir = sys_get_temp_dir() . '/imageresizer-mock-' . uniqid('', true);
    mkdir($dir, 0700);
    $file = $dir . '/fixture.png';
    function chunkForStub($tag, $data)
    {
        return pack('N', strlen($data)) . $tag . $data . pack('N', crc32($tag . $data));
    }
    file_put_contents($file, "\x89PNG\r\n\x1a\n" . chunkForStub('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
        . chunkForStub('IDAT', gzcompress("\x00\xff\x00\x00")) . chunkForStub('IEND', ''));
    $icc = file_get_contents(SimpleImage::PROFILE_SRGB);
    $image = new SimpleImage();
    checkStub($image->load($file), 'AUTO uses simulated Imagick');
    checkStub($image->getProcessorVersion() === SimpleImage::PROCESSOR_IMAGICK, 'actual backend is Imagick');
    checkStub($image->getImage()->profiles['icc'] === $icc, 'default sRGB ICC attached');
    checkStub($image->getImage()->conversions === 0, 'assigning ICC does not convert pixels');
    checkStub($image->setProfile(SimpleImage::PROFILE_SRGB, false), 'setProfile without replacement succeeds');
    checkStub($image->getImage()->conversions === 0, 'setProfile does not convert colors');
    checkStub($image->setProfile(SimpleImage::PROFILE_SRGB, true), 'replace ICC succeeds');
    checkStub($image->getImage()->conversions === 0, 'forced replacement does not convert colors');
    checkStub($image->convertToProfile(SimpleImage::PROFILE_SRGB), 'convertToProfile succeeds');
    checkStub($image->getImage()->conversions === 1, 'convertToProfile calls color conversion');
    $image->cropBySelectedRegion(10, 12, 0, 0);
    checkStub($image->getWidth() === 10 && $image->getHeight() === 12, 'crop sizes');
    checkStub($image->getImage()->profiles['icc'] === $icc, 'crop preserves ICC');
    $image->resizeAllInCenter(8, 9, '#0f0');
    checkStub($image->getWidth() === 8 && $image->getHeight() === 9, 'contain sizes');
    checkStub($image->getImage()->profiles['icc'] === $icc, 'contain preserves ICC');
    $image->resizeInCenter(5, 3);
    checkStub($image->getImage()->profiles['icc'] === $icc, 'cover preserves ICC');
    checkStub($image->setProfile('/not/a/profile.icc') === false, 'missing profile returns false');
    checkStub($image->getError()->getCode() === ImageError::PROFILE_NOT_FOUND, 'typed error object');
    checkStub(!preg_match('/[А-ЯЁа-яё]/u', $image->getError()->getMessage()), 'error is in English');
    checkStub($image->load('/file/does/not/exist') === false, 'load error returns false');
    checkStub($image->getError()->getCode() === ImageError::LOAD_FAILED, 'load error code');
    checkStub($image->getWidth() === 5 && $image->getHeight() === 3, 'failed load keeps the previous image');
    checkStub($image->setProcessorVersion(SimpleImage::PROCESSOR_GD) === false, 'changing backend after load fails');
    checkStub($image->setProfile(SimpleImage::PROFILE_SRGB), 'success clears errors');
    checkStub($image->getError() === null, 'getError returns null after success');
    checkStub($image->save($dir . '/output.png', 'png'), 'saving via backend works');
    ob_start(); $ok = $image->output('png'); $bytes = ob_get_clean();
    checkStub($ok === true && $bytes === 'stub-image-data', 'output via backend works');
    unlink($dir . '/output.png'); unlink($file); rmdir($dir);
    echo "DONE: Imagick wrapper mock tests passed (not a real Imagick integration test)\n";
}
