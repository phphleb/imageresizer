<?php
/** Интеграция обёртки с имитацией Imagick. Не заменяет тест с расширением Imagick. */
namespace Phphleb\Imageresizer {
    function extension_loaded($name) { return $name === 'imagick'; }
}
namespace {
    if (\extension_loaded('imagick')) {
        echo "SKIP: real Imagick installed; run tests/integration.php\n";
        exit(0);
    }
    class ImagickPixel { public function __construct($color) {} }
    class Imagick
    {
        const FILTER_LANCZOS = 1;
        const COMPOSITE_OVER = 1;
        const COLORSPACE_SRGB = 13;
        const COLORSPACE_RGB = 1;
        const COLORSPACE_CMYK = 12;
        const COLORSPACE_GRAY = 2;
        const ORIENTATION_TOPLEFT = 1;
        public static $saved = array();
        public static $failProfile = false;
        public static $failFormat = false;
        public static $failCopyProfile = false;
        public static $failOption = false;
        public $profiles = array();
        public $options = array();
        public $metadata = array('exif:GPSLatitude' => '12.34', 'xmp:creator' => 'tester');
        public $conversions = 0;
        public $width = 0;
        public $height = 0;
        public $pixels = 'unset';
        public $format = 'png';
        public $colorspace = self::COLORSPACE_SRGB;
        public $orientation = 1;
        public $transformations = array();
        public $stripCalled = false;
        public function readImage($file)
        {
            $info = @getimagesize($file);
            if (!$info) return false;
            $this->width = $info[0]; $this->height = $info[1]; $this->pixels = 'loaded';
            if (strpos($file, 'profiled') !== false) {
                $this->profiles['icc'] = file_get_contents(\Phphleb\Imageresizer\SimpleImage::PROFILE_SRGB);
            }
            if (strpos($file, 'cmyk') !== false) $this->colorspace = self::COLORSPACE_CMYK;
            if (strpos($file, 'gray') !== false) $this->colorspace = self::COLORSPACE_GRAY;
            if (strpos($file, 'rotate') !== false) $this->orientation = 6;
            return true;
        }
        public function getNumberImages() { return 1; }
        public function setIteratorIndex($idx) { return true; }
        public function getImageOrientation() { return $this->orientation; }
        public function setImageOrientation($orientation) { $this->orientation = $orientation; return true; }
        public function rotateImage($background, $degrees) {
            $this->transformations[] = 'rotate:' . $degrees;
            if (abs($degrees) === 90) {
                $tmp = $this->width; $this->width = $this->height; $this->height = $tmp;
            }
            $this->pixels .= ':rotated'; return true;
        }
        public function flopImage() { $this->transformations[] = 'flop'; return true; }
        public function flipImage() { $this->transformations[] = 'flip'; return true; }
        public function getImageColorspace() { return $this->colorspace; }
        public function setImageColorspace($s) { $this->colorspace = $s; return true; }
        public function getImageWidth() { return $this->width; }
        public function getImageHeight() { return $this->height; }
        public function getImageDepth() { return 8; }
        public function setImageDepth($depth) { return true; }
        public function resizeImage($w,$h,$filter,$blur,$bestfit) {
            $this->width=$w; $this->height=$h; $this->pixels .= ':resized'; return true;
        }
        public function newImage($w,$h,$color,$format) {
            $this->width=$w; $this->height=$h; $this->pixels='canvas'; return true;
        }
        public function compositeImage($img,$mode,$x,$y) {
            $this->pixels .= ':composited'; return true;
        }
        public function getImageProperties($pattern='*') { return $this->metadata; }
        public function setImageProperty($name,$value) { $this->metadata[$name]=$value; return true; }
        public function getImageProfiles($pattern='*',$includeValues=true) {
            $result = $this->profiles;
            if ($pattern!=='*') $result=array_intersect_key($result,array($pattern=>1));
            return $includeValues ? $result : array_keys($result);
        }
        public function setImageProfile($name,$bytes) {
            if (self::$failProfile || self::$failCopyProfile) return false;
            $this->profiles[$name]=$bytes; return true;
        }
        public function profileImage($name,$bytes) {
            if (self::$failProfile) return false;
            $this->conversions++; $this->profiles[$name]=$bytes;
            $this->pixels.=':converted'; return true;
        }
        public function stripImage() {
            $this->stripCalled=true; $this->metadata=array(); $this->profiles=array(); return true;
        }
        public function setImageFormat($format) {
            if (self::$failFormat) return false;
            $this->format=$format; return true;
        }
        public function setOption($name,$value) {
            if (self::$failOption) return false;
            $this->options[$name]=$value; return true;
        }
        public function setImageCompressionQuality($quality) { return true; }
        public function writeImage($path) {
            self::$saved[$path]=clone $this;
            return file_put_contents($path, $this->format === 'png' ? pngIm($this->width, $this->height) : 'stub-image-data') !== false;
        }
        public function getImageBlob() {
            self::$saved[':blob']=clone $this;
            return $this->format === 'png' ? pngIm($this->width, $this->height) : 'stub-image-data';
        }
    }

    require dirname(__DIR__) . '/SimpleImage.php';
    require __DIR__ . '/_support.php';
    use Phphleb\Imageresizer\SimpleImage;
    use Phphleb\Imageresizer\ImageError;
    function assertIm($value,$message) {
        if (!$value) { fwrite(STDERR,"FAIL: $message\n"); exit(1); }
        echo "PASS: $message\n";
    }
    function chunkIm($tag,$data) {return pack('N',strlen($data)).$tag.$data.pack('N',crc32($tag.$data));}
    function pngIm($w,$h) {
        $data=str_repeat("\x00".str_repeat("\xff\x00\x00",$w),$h);
        return "\x89PNG\r\n\x1a\n".chunkIm('IHDR',pack('NNCCCCC',$w,$h,8,2,0,0,0))
            .chunkIm('IDAT',gzcompress($data)).chunkIm('IEND','');
    }
    $dir=sys_get_temp_dir().'/imageresizer-new-'.uniqid('',true);
    mkdir($dir,0700);
    $plain=$dir.'/plain.png'; $profiled=$dir.'/profiled.png'; $cmyk=$dir.'/cmyk.png'; $gray=$dir.'/gray.png'; $rotate=$dir.'/rotate.png';
    foreach (array($plain,$profiled,$cmyk,$gray,$rotate) as $path) file_put_contents($path,pngIm(2,3));
    $icc=file_get_contents(SimpleImage::PROFILE_SRGB);
    $custom=$dir.'/cmyk.icc'; $cmykIcc=$icc;
    $cmykIcc=substr_replace($cmykIcc,'CMYK',16,4); file_put_contents($custom,$cmykIcc);
    $im=new SimpleImage();
    assertIm($im->getImageColorspace()===null && $im->getProfileName()===null,'null properties before load');
    assertIm($im->load($plain),'load profileless image');
    assertIm(isset($im->getImage()->options['png:preserve-iCCP']),
        'PNG decoder preserves embedded ICC bytes when opening an image');
    assertIm($im->getProcessorVersion()===SimpleImage::PROCESSOR_IMAGICK,'Imagick selected');
    assertIm($im->getImageColorspace()==='RGB','RGB family recognized without assuming sRGB');
    assertIm($im->getProfileName()===null && $im->getImage()->profiles===array(),'no automatic ICC assignment');
    assertIm($im->convertToProfile()===false && $im->getError()->getCode()===ImageError::SOURCE_PROFILE_MISSING,'conversion without source ICC fails');
    assertIm($im->setProcessorVersion(SimpleImage::PROCESSOR_IMAGICK)===true,'same actual backend allowed');
    assertIm($im->setProcessorVersion(SimpleImage::PROCESSOR_GD)===false,'switching actual backend disallowed');
    assertIm($im->setProfile(SimpleImage::PROFILE_SRGB),'explicit ICC assignment');
    assertIm(strpos($im->getProfileName(),'sRGB IEC61966-2.1')!==false,'embedded ICC name parsed');
    assertIm($im->getImage()->conversions===0,'assigning ICC leaves pixels unchanged');
    assertIm($im->convertToProfile(SimpleImage::PROFILE_SRGB),'explicit conversion succeeds');
    assertIm($im->getImage()->conversions===1,'conversion changes color data');
    assertIm($im->setProfile($custom,true)===false && $im->getError()->getCode()===ImageError::PROFILE_COLORSPACE_MISMATCH,'incompatible ICC rejected');
    assertIm($im->getImage()->profiles['icc']===$icc,'rejected ICC leaves old profile');
    $im->resizeToWidth(6);
    assertIm($im->getWidth()===6 && $im->getHeight()===9,'proportional resize');
    $im->cropBySelectedRegion(5,8,0,0);
    assertIm($im->getImage()->profiles['icc']===$icc,'crop retains ICC');
    $im->resizeAllInCenter(8,10,'#0f0');
    assertIm($im->getImage()->profiles['icc']===$icc,'contain retains ICC');
    $im->resizeInCenter(5,3);
    assertIm($im->getImage()->profiles['icc']===$icc,'cover retains ICC');
    $out=$dir.'/clean.png';
    assertIm($im->save($out,'png'),'save with default metadata strip');
    assertIm(Imagick::$saved[$out]->metadata===array() && Imagick::$saved[$out]->stripCalled,'EXIF/XMP removed by default');
    assertIm(Imagick::$saved[$out]->profiles['icc']===$icc,'ICC preserved when stripping');
    assertIm(suitePngIcc(file_get_contents($out))===$icc,'PNG iCCP repaired when the encoder discards ICC bytes');
    assertIm(isset(Imagick::$saved[$out]->options['png:preserve-iCCP']) &&
        Imagick::$saved[$out]->options['png:preserve-iCCP']==='true',
        'PNG encoder preserves full ICC instead of replacing it with an sRGB chunk');
    assertIm($im->getImage()->metadata!==array(),'saving does not strip the active image');
    $preserve = new SimpleImage();
    assertIm($preserve->load($plain, false),'load(..., false) selects metadata preservation');
    assertIm($preserve->setProfile(SimpleImage::PROFILE_SRGB),'assign ICC to metadata-preserving image');
    $out2=$dir.'/keep.png';
    assertIm($preserve->save($out2,'png'),'save with metadata preserved');
    assertIm(suitePngIcc(file_get_contents($out2))===$icc,'ICC embedded when metadata is preserved');
    assertIm(Imagick::$saved[$out2]->metadata!==array() && !Imagick::$saved[$out2]->stripCalled,
        'EXIF/XMP retained when load(..., false)');
    assertIm(isset(Imagick::$saved[$out2]->options['png:preserve-iCCP']),
        'PNG ICC preservation also works with metadata stripping disabled');
    $preserve->resizeInCenter(4,3);
    assertIm($preserve->getImage()->metadata!==array(),'crop/fit preserves metadata in preservation mode');
    $out2a=$dir.'/keep-after-fit.png';
    assertIm($preserve->save($out2a,'png'),'save after crop in preservation mode');
    assertIm(Imagick::$saved[$out2a]->metadata!==array(), 'metadata survives crop in preservation mode');
    ob_start(); $ok=$im->output('png'); $bytes=ob_get_clean();
    assertIm($ok && suitePngIcc($bytes)===$icc && Imagick::$saved[':blob']->metadata===array(),
        'output stream also strips metadata in default mode');
    assertIm(isset(Imagick::$saved[':blob']->options['png:preserve-iCCP']),
        'output() keeps the complete PNG ICC profile');
    Imagick::$failOption=true;
    assertIm($im->save($dir.'/bad-option.png','png')===false &&
        $im->getError()->getCode()===ImageError::SAVE_FAILED,
        'PNG profile preservation option failure is reported');
    Imagick::$failOption=false;
    Imagick::$failFormat=true;
    assertIm($im->save($dir.'/bad.png','png')===false && $im->getError()->getCode()===ImageError::SAVE_FAILED,'setImageFormat false handled');
    Imagick::$failFormat=false;
    Imagick::$failCopyProfile=true;
    assertIm($im->save($dir.'/bad2.png','png')===false && $im->getError()->getCode()===ImageError::SAVE_FAILED,'restoring ICC failed: save false');
    Imagick::$failCopyProfile=false;
    assertIm($im->load('/no/file.png')===false && $im->getWidth()===5,'failed reload retains earlier image');
    assertIm($im->load($profiled),'load image with embedded ICC');
    assertIm(strpos($im->getProfileName(),'sRGB')!==false,'existing ICC name returned');
    $imCmyk = new SimpleImage();
    assertIm($imCmyk->load($cmyk) && $imCmyk->getImageColorspace()==='CMYK','CMYK detected');
    assertIm($imCmyk->setProfile(SimpleImage::PROFILE_SRGB)===false && $imCmyk->getError()->getCode()===ImageError::PROFILE_COLORSPACE_MISMATCH,'RGB ICC cannot label CMYK pixels');
    assertIm($imCmyk->setProfile($custom),'CMYK profile allowed for CMYK pixels');
    $imGray = new SimpleImage();
    assertIm($imGray->load($gray) && $imGray->getImageColorspace()==='GRAY','GRAY detected');
    assertIm($imGray->setProfile(SimpleImage::PROFILE_SRGB)===false,'RGB ICC rejected for grayscale');
    assertIm($imGray->getProfileName()===null,'grayscale image still profileless');
    $im2=new SimpleImage();
    assertIm($im2->load($rotate, false) && $im2->getWidth()===3 && $im2->getHeight()===2,
        'EXIF orientation applies even when preserving metadata');
    assertIm($im2->getImage()->transformations===array('rotate:90'),
        'Imagick manual orientation works without autoOrientImage');
    assertIm($im2->save($dir.'/rotate-keep.png','png'),'save rotated image with metadata preserved');
    assertIm(Imagick::$saved[$dir.'/rotate-keep.png']->orientation===1,
        'EXIF orientation normalized when preservation requested');
    $im3=new SimpleImage();
    assertIm($im3->load($rotate) && $im3->getWidth()===3 && $im3->getHeight()===2,
        'EXIF orientation applied when stripping enabled');
    $out3=$dir.'/rotate-out.png'; $im3->save($out3,'png');
    assertIm(Imagick::$saved[$out3]->orientation===1,'output normalized orientation');
    foreach (glob($dir.'/*') as $item) unlink($item);
    rmdir($dir);
    echo "DONE: Imagick simulated backend checks (not a real codec test)\n";
}
