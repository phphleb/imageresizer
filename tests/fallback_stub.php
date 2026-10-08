<?php
/** Сценарий: Imagick установлен, но не декодирует файл; GD декодирует. */
namespace Phphleb\Imageresizer { function extension_loaded($name) { return $name==='gd' || $name==='imagick'; } }
namespace {
    if (\extension_loaded('gd') || \extension_loaded('imagick')) {
        echo "SKIP: real extensions available; this test uses mock extensions\n"; exit(0);
    }
    class Imagick { public function readImage($path) {return false;} }
    class FakeGD { public $w=2; public $h=3; }
    function imagecreatetruecolor($w,$h) {return new FakeGD();}
    function imagecreatefrompng($path) {return is_file($path)?new FakeGD():false;}
    function imagealphablending($i,$m){return true;}
    function imagesavealpha($i,$m){return true;}
    require dirname(__DIR__).'/SimpleImage.php';
    $path=sys_get_temp_dir().'/imageresizer-fallback-'.uniqid().'.png';
    $raw="\x89PNG\r\n\x1a\n".pack('N',13).'IHDR'.pack('NNCCCCC',2,3,8,2,0,0,0).pack('N',0);
    file_put_contents($path,$raw);
    // getimagesize() accepts valid header with incomplete data in this test.
    function expectFall($ok,$message) {
        if(!$ok) {fwrite(STDERR,"FAIL: $message\n");exit(1);}
        echo "PASS: $message\n";
    }
    $auto=new \Phphleb\Imageresizer\SimpleImage();
    expectFall($auto->load($path),'AUTO retries GD when Imagick returns false');
    expectFall($auto->getProcessorVersion()==='gd','GD became the actual processor');
    $forced=new \Phphleb\Imageresizer\SimpleImage();
    expectFall($forced->setProcessorVersion('imagick'),'force Imagick mode');
    expectFall($forced->load($path)===false,'forced Imagick does not silently fall back');
    expectFall($forced->getError()->getCode()===\Phphleb\Imageresizer\ImageError::LOAD_FAILED,'decoder failure has LOAD_FAILED code');
    unlink($path);
    echo "DONE: fallback simulation passed\n";
}
