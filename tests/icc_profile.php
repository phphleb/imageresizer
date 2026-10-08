<?php
/** Проверяет чтение имен ICC v2/v4 и защиту от повреждённых профилей. */
require dirname(__DIR__) . '/SimpleImage.php';
use Phphleb\Imageresizer\IccProfile;
use Phphleb\Imageresizer\SimpleImage;
function assertIcc($yes,$name) {if (!$yes) {fwrite(STDERR,"FAIL: $name\n");exit(1);}echo "PASS: $name\n";}
$srgb=file_get_contents(SimpleImage::PROFILE_SRGB);
assertIcc(IccProfile::valid($srgb),'bundled ICC header valid');
assertIcc(IccProfile::colorspace($srgb)==='RGB','bundled sRGB is RGB');
assertIcc(strpos(IccProfile::name($srgb),'sRGB IEC61966-2.1')!==false,'bundled ICC profile name is present');
assertIcc(!IccProfile::valid('broken data'),'garbled ICC rejected');
assertIcc(IccProfile::name(substr($srgb,0,200))===null,'truncated or incomplete profile has no parsed name');
$v4=$srgb;
$prefix=substr($v4,0,128);
$prefix=substr_replace($prefix,pack('N',256),0,4);
$head=pack('N',1).'desc'.pack('NN',144,112);
$utf16="\x00D\x00i\x00s\x00p\x00l\x00a\x00y\x00 \x00P\x003";
$block='mluc'."\x00\x00\x00\x00".pack('N',1).pack('N',12).'enUS'.pack('NN',strlen($utf16),28).$utf16;
$v4=$prefix.$head.$block.str_repeat("\x00",256-128-strlen($head)-strlen($block));
assertIcc(IccProfile::valid($v4),'synthetic v4 ICC header valid');
assertIcc(IccProfile::name($v4)==='Display P3','v4 mluc profile name parsed');
echo "DONE: ICC metadata parser tests passed\n";
