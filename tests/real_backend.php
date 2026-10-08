<?php
/**
 * Обязательная интеграционная проверка с настоящим PHP-расширением.
 * Запуск: php tests/real_backend.php imagick
 *         php tests/real_backend.php gd
 * В отличие от старого integration.php, отсутствие расширения — FAIL, не SKIP.
 */
require __DIR__ . '/_support.php';
require dirname(__DIR__) . '/SimpleImage.php';

use Phphleb\Imageresizer\ImageError;
use Phphleb\Imageresizer\SimpleImage;
use Phphleb\Imageresizer\ExifOrientation;

$backend = $argv[1] ?? '';
suiteAssert(in_array($backend, ['imagick', 'gd'], true), 'Explicit backend must be imagick or gd');
suiteAssert(extension_loaded($backend), "Required real PHP extension {$backend} is installed");
if ($backend === 'gd') suiteAssert(function_exists('imagecreatefromjpeg'), 'GD JPEG decoder installed');
else suiteAssert(class_exists('Imagick') && method_exists('Imagick', 'rotateImage') &&
    method_exists('Imagick', 'flopImage') && method_exists('Imagick', 'flipImage'),
    'Imagick supports rotation and both mirror directions');

$dir = suiteDirectory();
$png = $dir . '/fixture.png';
$transparent = $dir . '/transparent.png';
suitePng($png);
suitePng($transparent, 60, 40, true);
$jpeg = $dir . '/plain.jpg';
suiteJpegFromPng($png, $jpeg, $backend);
$icc = file_get_contents(SimpleImage::PROFILE_SRGB);
suiteAssert(is_string($icc) && strlen($icc) > 100, 'Bundled ICC profile is available');

function newSelected(string $backend): SimpleImage
{
    $image = new SimpleImage();
    suiteAssert($image->setProcessorVersion($backend), "Explicitly select {$backend}");
    return $image;
}

// Old API, public operations, dimensions and output buffer.
$image = newSelected($backend);
suiteAssert($image->getImage() === null && $image->getImageColorspace() === null &&
    $image->getProfileName() === null && $image->getWidth() === 1 && $image->getHeight() === 1,
    'Unloaded accessors retain documented defaults');
suiteAssert($image->load($png), 'Load PNG without metadata flag');
suiteAssert($image->getProcessorVersion() === $backend && $image->getImageFormat() === 'png' &&
    $image->getImageType() === IMAGETYPE_PNG && $image->getFilePath() === $png,
    'Backend and original file metadata');
suiteAssert($image->getWidth() === 60 && $image->getHeight() === 40, 'Original dimensions');
suiteAssert($image->getImageColorspace() === 'RGB' && $image->getProfileName() === null,
    'RGB colorspace does not automatically assign sRGB profile');
suiteAssert(!method_exists($image, 'setStripMetadata'),
    'Metadata preservation is configured only through load()');
$image->resizeToWidth(30);
suiteAssert($image->getError() === null && $image->getWidth() === 30 && $image->getHeight() === 20,
    'resizeToWidth preserves proportions');
$image->resizeToHeight(40);
suiteAssert($image->getError() === null && $image->getWidth() === 60 && $image->getHeight() === 40,
    'resizeToHeight preserves proportions');
$image->scale(50);
suiteAssert($image->getError() === null && $image->getWidth() === 30 && $image->getHeight() === 20,
    'scale uses percentages');
$image->resize(33, 21);
suiteAssert($image->getError() === null && $image->getWidth() === 33 && $image->getHeight() === 21,
    'Non-proportional resize');
$image->resizeInCenter(21, 21);
suiteAssert($image->getError() === null && $image->getWidth() === 21 && $image->getHeight() === 21,
    'Cover and centered crop');
$image->resizeAllInCenter(44, 31);
suiteAssert($image->getError() === null && $image->getWidth() === 44 && $image->getHeight() === 31,
    'Contain and centered transparent canvas');
$image->resizeAllInCenter(48, 37, '#123456');
suiteAssert($image->getError() === null && $image->getWidth() === 48 && $image->getHeight() === 37,
    'Contain and background hex');
$image->resizeAllInCenter(48, 37, $image->addRgbColor(12, 34, 56));
suiteAssert($image->getError() === null && $image->getWidth() === 48 && $image->getHeight() === 37,
    'Contain and RGB array');
$image->cropBySelectedRegion(22, 13, 5, 2);
suiteAssert($image->getError() === null && $image->getWidth() === 22 && $image->getHeight() === 13,
    'Crop by selected region');
$out = $dir . '/resized.png';
suiteAssert($image->save($out, 'png'), 'Write processed PNG');
suiteAssert(getimagesize($out)[0] === 22 && getimagesize($out)[1] === 13, 'PNG pixels actually encoded');
ob_start(); $ok = $image->output('png'); $blob = ob_get_clean();
suiteAssert($ok && substr($blob, 0, 8) === "\x89PNG\r\n\x1a\n" && getimagesizefromstring($blob)[0] === 22,
    'Output produces a valid PNG data stream');
suiteAssert($image->save($dir . '/resized.jpg', IMAGETYPE_JPEG, 90) &&
    getimagesize($dir . '/resized.jpg')[2] === IMAGETYPE_JPEG, 'Save accepts numeric IMAGETYPE_JPEG');
// Formats depend on the codec support actually compiled into PHP/ImageMagick.
foreach (['gif', 'webp', 'bmp'] as $format) {
    $supported = $backend === 'gd'
        ? (function_exists('image' . $format) && function_exists('imagecreatefrom' . $format))
        : count(\Imagick::queryFormats(strtoupper($format))) > 0;
    if (!$supported) { echo "SKIP: codec {$format} is unavailable for {$backend}\n"; continue; }
    $encoded = $dir . '/codec.' . $format;
    suiteAssert($image->save($encoded, $format, 90), "Save {$format} with real {$backend} codec");
    $reopened = newSelected($backend);
    suiteAssert($reopened->load($encoded) && $reopened->getWidth() === 22 && $reopened->getHeight() === 13,
        "Reload {$format} using real {$backend} codec");
}
if ($backend === 'imagick') {
    $plainSaved = new \Imagick($out);
    suiteAssert($plainSaved->getImageProfiles('icc', true) === [],
        'Saving a profileless source never adds an ICC implicitly');
}

// Transparent pixels must survive both read/save and canvas crop operations.
$alpha = newSelected($backend);
suiteAssert($alpha->load($transparent), 'Load RGBA PNG');
suiteAssert($alpha->save($dir . '/alpha.png', 'png'), 'Save transparent PNG');
if ($backend === 'gd') {
    $decoded = imagecreatefrompng($dir . '/alpha.png');
    suiteAssert(imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha'] === 127,
        'GD retains transparent alpha when saving');
} else {
    $decoded = new \Imagick($dir . '/alpha.png');
    suiteAssert($decoded->getImagePixelColor(0, 0)->getColor(true)['a'] < .02,
        'Imagick retains transparent alpha when saving');
}
$alpha->cropBySelectedRegion(20, 20, -8, -6);
suiteAssert($alpha->getError() === null && $alpha->save($dir . '/alpha-crop.png', 'png'),
    'Cropping outside image bounds creates transparent canvas');

// Failures must not throw or destroy previously decoded pixels.
$errors = newSelected($backend);
suiteAssert($errors->save($dir . '/bad.png', 'png') === false &&
    $errors->getError()->getCode() === ImageError::SAVE_FAILED, 'Save before load reports error');
suiteAssert($errors->load($png) && $errors->getError() === null, 'Successful load clears previous error');
suiteAssert($errors->load($dir . '/missing.jpg') === false &&
    $errors->getError()->getCode() === ImageError::LOAD_FAILED &&
    $errors->getWidth() === 60 && $errors->getHeight() === 40,
    'Failed reload preserves previous valid image');
suiteAssert($errors->save($dir . '/after-failure.png', 'png'), 'Previous image stays usable after failed reload');
$errors->resize(0, 10);
suiteAssert($errors->getError() !== null && $errors->getError()->getCode() === ImageError::INVALID_ARGUMENT,
    'Invalid resize dimensions expose ImageError');
$errors->resize('not a number', 10);
suiteAssert($errors->getError()->getCode() === ImageError::INVALID_ARGUMENT,
    'Non-numeric dimensions expose ImageError');
suiteAssert($errors->output('invalid-format') === false &&
    $errors->getError()->getCode() === ImageError::INVALID_ARGUMENT,
    'Invalid output format returns false');
suiteAssert($errors->save($dir . '/bad.xxx', 'unknown') === false &&
    $errors->getError()->getCode() === ImageError::INVALID_ARGUMENT,
    'Invalid save format returns false');
suiteAssert($errors->setProcessorVersion($backend) && $errors->getError() === null,
    'Selecting the active engine is allowed after load');
suiteAssert($errors->setProcessorVersion('other') === false &&
    $errors->getError()->getCode() === ImageError::INVALID_ARGUMENT,
    'Unknown backend returns error');
suiteAssert($errors->setProcessorVersion($backend) && $errors->getError() === null,
    'Successful action clears error');

// Orientation values 1..8, including all mirrors (2,4,5,7).
$palette = [1 => 'RGBY', 2 => 'GRYB', 3 => 'YBGR', 4 => 'BYRG',
            5 => 'RBGY', 6 => 'BRYG', 7 => 'YGBR', 8 => 'GYRB'];
foreach ($palette as $orientation => $expected) {
    $orientedFile = $dir . '/orient-' . $orientation . '.jpg';
    suiteJpegWithMetadata($jpeg, $orientedFile, $orientation);
    suiteAssert(ExifOrientation::fromFile($orientedFile) === $orientation,
        "EXIF fixture has orientation {$orientation}");
    $oriented = newSelected($backend);
    suiteAssert($oriented->load($orientedFile), "Decode orientation {$orientation}");
    $w = $orientation >= 5 ? 40 : 60;
    $h = $orientation >= 5 ? 60 : 40;
    suiteAssert($oriented->getWidth() === $w && $oriented->getHeight() === $h,
        "Orientation {$orientation}: pixel dimensions normalized");
    suiteAssert(suiteQuadrants($oriented->getImage(), $w, $h, $backend) === $expected,
        "Orientation {$orientation}: all four quadrants correct");
    $orientedOut = $dir . '/oriented-output-' . $orientation . '.jpg';
    suiteAssert($oriented->save($orientedOut, 'jpeg', 95),
        "Orientation {$orientation}: normalized JPEG saved");
    suiteAssert(ExifOrientation::fromFile($orientedOut) === 1,
        "Orientation {$orientation}: no double-rotation EXIF in saved file");
    suiteAssert(strpos(file_get_contents($orientedOut), 'SensitiveCamera') === false,
        "Orientation {$orientation}: camera metadata removed by default");
}

// Metadata flag belongs to load(); it cannot change orientation handling.
$withMeta = $dir . '/orient-6.jpg';
$private = newSelected($backend);
suiteAssert($private->load($withMeta, false), 'Load photo with metadata preservation requested');
suiteAssert($private->getWidth() === 40 && $private->getHeight() === 60 &&
    suiteQuadrants($private->getImage(), 40, 60, $backend) === 'BRYG',
    'Preserving metadata does not disable EXIF orientation correction');
$preserved = $dir . '/preserved.jpg';
suiteAssert($private->save($preserved, 'jpeg', 95), 'Write JPEG loaded with stripMetadata=false');
$preservedBytes = file_get_contents($preserved);
$clean = newSelected($backend);
suiteAssert($clean->load($withMeta), 'Default mode loads image with sensitive EXIF/XMP');
$cleanFile = $dir . '/clean.jpg';
suiteAssert($clean->save($cleanFile, 'jpeg', 95), 'Default mode saves sanitized JPEG');
$cleanBytes = file_get_contents($cleanFile);
suiteAssert(strpos($cleanBytes, 'SensitiveCamera') === false &&
    strpos($cleanBytes, 'PrivateXMPMarker') === false &&
    ExifOrientation::fromFile($cleanFile) === 1,
    'Default load strips sensitive EXIF/XMP and keeps orientation correct');
ob_start(); $cleanOutputOk = $clean->output('jpeg'); $cleanBlob = ob_get_clean();
suiteAssert($cleanOutputOk && substr($cleanBlob, 0, 2) === "\xff\xd8" &&
    strpos($cleanBlob, 'SensitiveCamera') === false && strpos($cleanBlob, 'PrivateXMPMarker') === false,
    'output() also removes sensitive metadata by default');
if ($backend === 'imagick') {
    suiteAssert(strpos($preservedBytes, 'SensitiveCamera') !== false,
        'Imagick preserves EXIF Make when load(..., false)');
    suiteAssert(ExifOrientation::fromFile($preserved) === 1,
        'Imagick normalizes retained EXIF Orientation');
    // The XMP coder may omit unsupported XMP when loading; if it was decoded, it must survive.
    $original = new \Imagick($withMeta);
    $profileKeys = array_map('strtolower', $original->getImageProfiles('*', false) ?: []);
    if (in_array('xmp', $profileKeys, true)) {
        suiteAssert(strpos($preservedBytes, 'PrivateXMPMarker') !== false,
            'Imagick preserves recognized XMP when load(..., false)');
    }
    $private->resizeInCenter(35, 35);
    suiteAssert($private->getError() === null, 'Crop retains metadata mode');
    $preservedCrop = $dir . '/preserved-crop.jpg';
    suiteAssert($private->save($preservedCrop, 'jpeg', 95), 'Write metadata-preserving crop');
    suiteAssert(strpos(file_get_contents($preservedCrop), 'SensitiveCamera') !== false &&
        ExifOrientation::fromFile($preservedCrop) === 1,
        'Imagick keeps EXIF across cropping, with normalized Orientation');
} else {
    suiteAssert(strpos($preservedBytes, 'SensitiveCamera') === false,
        'GD still drops all old EXIF even with load(..., false)');
}

if ($backend === 'imagick') {
    $profile = newSelected($backend);
    suiteAssert($profile->load($png), 'Load source without ICC for profile tests');
    suiteAssert($profile->getProfileName() === null,
        'No automatic sRGB assignment, including with Imagick');
    suiteAssert(!$profile->convertToProfile() &&
        $profile->getError()->getCode() === ImageError::SOURCE_PROFILE_MISSING,
        'Conversion without a source ICC fails');
    suiteAssert($profile->setProfile(SimpleImage::PROFILE_SRGB), 'Assign bundled ICC explicitly');
    suiteAssert(stripos($profile->getProfileName(), 'sRGB') !== false,
        'getProfileName returns sRGB profile name');
    suiteAssert($profile->save($dir . '/icc-original.png', 'png'),
        'Embed assigned ICC into PNG');
    $encoded = file_get_contents($dir . '/icc-original.png');
    $encodedChunks = suitePngChunks($encoded);
    $writtenIcc = suitePngIcc($encoded);
    if ($writtenIcc !== $icc || isset($encodedChunks['sRGB'])) {
        fwrite(STDERR, 'PNG ICC diagnostic: chunks=' . implode(',', array_keys($encodedChunks)) .
            ', expected=' . strlen($icc) . ' bytes (' . hash('sha256', $icc) . ')' .
            ', actual=' . ($writtenIcc === null ? 'missing' :
                strlen($writtenIcc) . ' bytes (' . hash('sha256', $writtenIcc) . ')') . "\n");
    }
    suiteAssert($writtenIcc === $icc && !isset($encodedChunks['sRGB']),
        'Exact embedded ICC data survives PNG save with metadata stripping');
    $roundtripImage = newSelected($backend);
    $roundtripOk = $roundtripImage->load($dir . '/icc-original.png');
    $roundtripName = $roundtripImage->getProfileName();
    suiteAssert($roundtripOk && is_string($roundtripName) &&
        stripos($roundtripName, 'sRGB') !== false,
        'Loading a PNG with iCCP retains the ICC name');
    foreach (['resize', 'crop', 'fit'] as $op) {
        if ($op === 'resize') $profile->resize(26, 18);
        elseif ($op === 'crop') $profile->cropBySelectedRegion(16, 12, -2, -2);
        else $profile->resizeAllInCenter(23, 20);
        suiteAssert($profile->getError() === null, "ICC {$op} succeeded");
        $dst = $dir . '/icc-' . $op . '.png';
        suiteAssert($profile->save($dst, 'png'), "ICC {$op} output saved");
        suiteAssert(suitePngIcc(file_get_contents($dst)) === $icc,
            "ICC {$op} preserved byte-for-byte after reopening");
    }
    ob_start(); $profileOutputOk = $profile->output('png'); $profileBlob = ob_get_clean();
    suiteAssert($profileOutputOk && suitePngIcc($profileBlob) === $icc,
        'ICC profile survives output() stream after resizing and cropping');
    suiteAssert($profile->setProfile(SimpleImage::PROFILE_SRGB, false),
        'Setting an existing ICC without replace does not fail');
    suiteAssert($profile->setProfile(SimpleImage::PROFILE_SRGB, true),
        'Explicit ICC replacement works');
    suiteAssert($profile->convertToProfile(SimpleImage::PROFILE_SRGB), 'Explicit ICC color conversion');
    suiteAssert(stripos($profile->getProfileName(), 'sRGB') !== false,
        'Color conversion leaves destination ICC assigned');
    suiteAssert($profile->setProfile('/not/a/profile.icc') === false &&
        $profile->getError()->getCode() === ImageError::PROFILE_NOT_FOUND,
        'Missing ICC file returns defined error');
    $invalidIcc = $dir . '/corrupt.icc';
    file_put_contents($invalidIcc, 'corrupt');
    suiteAssert($profile->setProfile($invalidIcc) === false &&
        $profile->getError()->getCode() === ImageError::PROFILE_NOT_FOUND,
        'Invalid ICC data returns defined error');
    // RGB ICC must not be silently assigned to a CMYK source.
    $cmyk = new \Imagick($jpeg);
    suiteAssert($cmyk->transformImageColorspace(\Imagick::COLORSPACE_CMYK) !== false,
        'Prepare CMYK image for profile safety test');
    suiteAssert($cmyk->writeImage($dir . '/cmyk.jpg') !== false, 'Write CMYK fixture');
    $cmykImage = newSelected($backend);
    suiteAssert($cmykImage->load($dir . '/cmyk.jpg'), 'Load CMYK photo');
    suiteAssert($cmykImage->getImageColorspace() === 'CMYK', 'CMYK colorspace correctly recognized');
    suiteAssert($cmykImage->setProfile(SimpleImage::PROFILE_SRGB) === false &&
        $cmykImage->getError()->getCode() === ImageError::PROFILE_COLORSPACE_MISMATCH,
        'Do not mislabel CMYK pixels with sRGB ICC');
    $beforeLoad = newSelected($backend);
    suiteAssert($beforeLoad->setProfile(SimpleImage::PROFILE_SRGB),
        'Set profile before loading image');
    suiteAssert($beforeLoad->load($png) && stripos($beforeLoad->getProfileName(), 'sRGB') !== false,
        'Explicit pre-load ICC applied on load');
    suiteAssert($beforeLoad->load($png, false) && stripos($beforeLoad->getProfileName(), 'sRGB') !== false,
        'Profile assignment and metadata setting work on repeated load');
} else {
    $noIcc = newSelected($backend);
    suiteAssert($noIcc->load($png, false), 'GD accepts load(..., false) without errors');
    suiteAssert($noIcc->getImageColorspace() === 'RGB' && $noIcc->getProfileName() === null,
        'GD reports RGB pixels but no embedded ICC');
    suiteAssert($noIcc->setProfile(SimpleImage::PROFILE_SRGB) === false &&
        $noIcc->getError()->getCode() === ImageError::PROFILE_UNSUPPORTED,
        'GD reports that it cannot embed ICC profiles');
    suiteAssert($noIcc->convertToProfile() === false &&
        $noIcc->getError()->getCode() === ImageError::PROFILE_UNSUPPORTED,
        'GD reports that it cannot convert ICC profiles');
    // Restore old protected methods used by subclasses of the historical GD wrapper.
    class LegacyChild extends SimpleImage {
        public function file($p) { return $this->createFromFile($p); }
        public function canvas($w, $h) { return $this->createCanvas($w, $h); }
        public function alpha($i) { return $this->keepAlpha($i); }
        public function copy($i) { return $this->imageCopyResampled($i, $i, 0, 0, 0, 0, 1, 1, 1, 1); }
    }
    $child = new LegacyChild();
    suiteAssert($child->file($png) !== false, 'Legacy protected createFromFile()');
    $canvas = $child->canvas(8, 5);
    suiteAssert($canvas !== false && imagesx($canvas) === 8, 'Legacy protected createCanvas()');
    suiteAssert($child->alpha($canvas) && $child->copy($canvas),
        'Legacy protected keepAlpha() and imageCopyResampled()');
}

suiteAssert($image->getError() === null, 'Successful previous image has no error');
echo "DONE: REAL {$backend} integration checks passed\n";
