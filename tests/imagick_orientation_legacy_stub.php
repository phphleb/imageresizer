<?php
/** Manual EXIF orientation checks with an Imagick stub lacking autoOrientImage(). */
namespace {
    class ImagickPixel {
        public function __construct($background) {}
    }
    class Imagick {
        const ORIENTATION_TOPLEFT = 1;
        public static $failRotate = false;
        public static $failFlop = false;
        public static $failFlip = false;
        public $grid;
        public $orientation;
        public $profiles;
        public function readImage($filename) {
            if (!preg_match('/orient-([1-8])/', $filename, $match)) return false;
            $this->orientation = strpos($filename, 'undefined-') !== false ? 0 : (int) $match[1];
            $this->grid = array(array('A', 'B', 'C'), array('D', 'E', 'F'));
            $this->profiles = array('exif' => suiteExif((int) $match[1]));
            return true;
        }
        public function getNumberImages() { return 1; }
        public function getImageOrientation() { return $this->orientation; }
        public function setImageOrientation($orientation) { $this->orientation = $orientation; return true; }
        public function getImageProfiles($name, $values = true) {
            return isset($this->profiles[$name]) ? array($name => $this->profiles[$name]) : array();
        }
        public function setImageProfile($name, $bytes) { $this->profiles[$name] = $bytes; return true; }
        public function getImageWidth() { return count($this->grid[0]); }
        public function getImageHeight() { return count($this->grid); }
        public function flopImage() {
            if (self::$failFlop) return false;
            foreach ($this->grid as &$row) $row = array_reverse($row);
            unset($row);
            return true;
        }
        public function flipImage() {
            if (self::$failFlip) return false;
            $this->grid = array_reverse($this->grid);
            return true;
        }
        public function rotateImage($pixel, $degrees) {
            if (self::$failRotate) return false;
            $h = count($this->grid);
            $w = count($this->grid[0]);
            $newW = $degrees === 180 ? $w : $h;
            $newH = $degrees === 180 ? $h : $w;
            $result = array_fill(0, $newH, array_fill(0, $newW, '?'));
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    if ($degrees === 90) { $nx = $h - 1 - $y; $ny = $x; }
                    elseif ($degrees === -90) { $nx = $y; $ny = $w - 1 - $x; }
                    elseif ($degrees === 180) { $nx = $w - 1 - $x; $ny = $h - 1 - $y; }
                    else return false;
                    $result[$ny][$nx] = $this->grid[$y][$x];
                }
            }
            $this->grid = $result;
            return true;
        }
    }
}
namespace {
    require __DIR__ . '/_support.php';
    require dirname(__DIR__) . '/ProcessorInterface.php';
    require dirname(__DIR__) . '/ExifOrientation.php';
    require dirname(__DIR__) . '/ImagickProcessor.php';
    use Phphleb\Imageresizer\ImagickProcessor;
    use Phphleb\Imageresizer\ExifOrientation;

    suiteAssert(!method_exists('Imagick', 'autoOrientImage'),
        'Legacy Imagick deliberately lacks autoOrientImage');
    $expected = array(
        1 => 'ABCDEF', 2 => 'CBAFED', 3 => 'FEDCBA', 4 => 'DEFABC',
        5 => 'ADBECF', 6 => 'DAEBFC', 7 => 'FCEBDA', 8 => 'CFBEAD',
    );
    foreach ($expected as $orientation => $pixels) {
        foreach (array(true, false) as $stripMetadata) {
            $processor = new ImagickProcessor();
            suiteAssert($processor->load('orient-' . $orientation . '.jpg', $stripMetadata),
                "Manual orientation {$orientation}, metadata strip=" . (int) $stripMetadata);
            $image = $processor->getImage();
            $flattened = implode('', array_merge(...$image->grid));
            suiteAssert($flattened === $pixels,
                "Orientation {$orientation}: correct rotations and reflections");
            suiteAssert($image->orientation === 1,
                "Orientation {$orientation}: orientation flag reset");
            suiteAssert($image->getImageWidth() === ($orientation >= 5 ? 2 : 3) &&
                $image->getImageHeight() === ($orientation >= 5 ? 3 : 2),
                "Orientation {$orientation}: correct final dimensions");
            if (!$stripMetadata) {
                suiteAssert(ExifOrientation::fromProfile($image->profiles['exif']) === 1 &&
                    strpos($image->profiles['exif'], 'SensitiveCamera') !== false,
                    "Orientation {$orientation}: preserved EXIF normalized without losing other tags");
            }
        }
    }
    $processor = new ImagickProcessor();
    suiteAssert($processor->load('orient-1.jpg'), 'Load an earlier valid image');
    $prior = $processor->getImage();
    Imagick::$failRotate = true;
    suiteAssert(!$processor->load('orient-6.jpg') && $processor->getImage() === $prior,
        'Failed rotation does not replace prior decoded image');
    Imagick::$failRotate = false;
    Imagick::$failFlop = true;
    suiteAssert(!$processor->load('orient-5.jpg') && $processor->getImage() === $prior,
        'Failed reflection does not replace prior decoded image');
    Imagick::$failFlop = false;
    Imagick::$failFlip = true;
    suiteAssert(!$processor->load('orient-4.jpg') && $processor->getImage() === $prior,
        'Failed vertical flip does not replace prior decoded image');
    Imagick::$failFlip = false;

    // An undefined Imagick orientation still allows parsing a JPEG APP1 marker.
    $file = suiteDirectory() . '/undefined-orient-6.jpg';
    $exif = suiteExif(6);
    file_put_contents($file, "\xff\xd8\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . "\xff\xd9");
    $processor = new ImagickProcessor();
    suiteAssert($processor->load($file, false) &&
        implode('', array_merge(...$processor->getImage()->grid)) === 'DAEBFC',
        'Undefined orientation falls back to the JPEG EXIF parser');
    echo "DONE: manual Imagick EXIF 1-8 without autoOrientImage\n";
}
