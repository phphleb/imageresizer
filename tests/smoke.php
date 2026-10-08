<?php
require dirname(__DIR__) . '/SimpleImage.php';
use Phphleb\Imageresizer\SimpleImage;
use Phphleb\Imageresizer\ImageError;
function check($yes, $message) { if (!$yes) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } echo "PASS: $message\n"; }
$i = new SimpleImage();
check($i->getError() === null, 'initial error null');
check($i->getWidth() === 1 && $i->getHeight() === 1, 'old dimension fallback');
check($i->setProfile(SimpleImage::PROFILE_SRGB) === true, 'bundled sRGB available');
check($i->setProfile('not-a-real-profile') === false && $i->getError()->getCode() === ImageError::PROFILE_NOT_FOUND, 'profile errors');
check($i->setProcessorVersion('invalid') === false && $i->getError()->getCode() === ImageError::INVALID_ARGUMENT, 'invalid backend errors');
if (!extension_loaded('imagick')) {
    check($i->setProcessorVersion(SimpleImage::PROCESSOR_IMAGICK) === false, 'forced missing imagick fails');
    check($i->getError()->getCode() === ImageError::BACKEND_UNAVAILABLE, 'forced backend error code');
}
if (!extension_loaded('gd')) {
    check($i->setProcessorVersion(SimpleImage::PROCESSOR_GD) === false, 'forced missing gd fails');
}
if (!extension_loaded('gd') && !extension_loaded('imagick')) {
    check($i->load('/nonexistent') === false, 'missing backends return false');
    check($i->getError()->getCode() === ImageError::BACKEND_UNAVAILABLE, 'no backends message');
}
check($i->save('/tmp/no.jpg', 'jpeg') === false, 'save before load returns false');
check($i->getError()->getCode() === ImageError::SAVE_FAILED, 'save failure code');
check($i->setProfile(SimpleImage::PROFILE_SRGB) === true && $i->getError() === null, 'success clears error');

check(method_exists($i, 'convertToProfile'), 'conversion API exists');
check($i->convertToProfile(SimpleImage::PROFILE_SRGB) === false, 'conversion requires loaded image');
check($i->getError()->getCode() === ImageError::PROCESSING_FAILED, 'conversion without image code');
check(!defined(SimpleImage::class . '::PROFILE_ADOBE_RGB'), 'no unbundled Adobe constant');

check(!method_exists($i, 'setStripMetadata'), 'v3 has no setStripMetadata');
check($i->getProfileName() === null && $i->getImageColorspace() === null, 'unloaded profile accessors');
$load = new ReflectionMethod(SimpleImage::class, 'load');
check($load->getNumberOfParameters() === 2 && $load->getParameters()[1]->getDefaultValue() === true,
    'load takes metadata flag with stripping enabled by default');
