<?php
namespace Phphleb\Imageresizer;

/** Обработчик Imagick: пиксели, ICC и метаданные изображений. */
class ImagickProcessor implements ProcessorInterface
{
    private $image;
    private $stripMetadata = true;

    /**
     * Загружает изображение. EXIF Orientation всегда применяется к пикселям, даже
     * когда остальные метаданные пользователь попросил сохранить.
     */
    public function load($filename, $stripMetadata = true): bool
    {
        $image = new \Imagick();
        // По умолчанию ImageMagick может при чтении PNG заменить ICC sRGB
        // краткой меткой sRGB. Сохраняем исходный iCCP и при декодировании.
        if (method_exists($image, 'setOption') &&
            $image->setOption('png:preserve-iCCP', 'true') === false) return false;
        if ($image->readImage($filename) === false) return false;
        if ($image->getNumberImages() > 1 && $image->setIteratorIndex(0) === false) return false;
        if (!$this->orient($image, $filename)) return false;
        $this->stripMetadata = $stripMetadata !== false;
        if (!$this->stripMetadata && !$this->normalizeExif($image)) return false;
        $this->image = $image;
        return true;
    }

    /** Сохраняет изображение в файл, очищая EXIF/IPTC/XMP при включённой настройке. */
    public function save($filename, $format, $quality): bool
    {
        $ready = $this->prepare($format, $quality);
        if ($ready === false) return false;
        return $ready->writeImage($filename) !== false;
    }

    /** Выводит изображение в поток браузера. */
    public function output($format): bool
    {
        $ready = $this->prepare($format, 100);
        if ($ready === false) return false;
        $blob = $ready->getImageBlob();
        if (!is_string($blob) || $blob === '') return false;
        echo $blob;
        return true;
    }

    /** Готовит отдельную копию для записи, не меняя исходный объект и его метаданные. */
    private function prepare($format, $quality)
    {
        if (!$this->image) return false;
        $format = strtolower((string) $format);
        if ($format === 'jpg') $format = 'jpeg';
        if (!in_array($format, array('jpeg', 'png', 'gif', 'webp', 'bmp', 'wbmp', 'avif'), true)) return false;
        $copy = clone $this->image;
        if ($this->stripMetadata) {
            $icc = $this->getProfile();
            if ($copy->stripImage() === false) return false;
            // stripImage удаляет все профили, поэтому ICC возвращается отдельно.
            if ($icc !== null && $copy->setImageProfile('icc', $icc) === false) return false;
        }
        if ($copy->setImageFormat($format) === false) return false;
        if ($format === 'png' && $this->getProfile() !== null) {
            // По умолчанию PNG-кодер ImageMagick может заменить известный
            // sRGB ICC на короткий chunk sRGB. Явно назначенный/исходный ICC
            // должен остаться встроенным iCCP даже после очистки метаданных.
            if ($copy->setOption('png:preserve-iCCP', 'true') === false) return false;
        }
        if (($format === 'jpeg' || $format === 'webp') &&
            $copy->setImageCompressionQuality((int) $quality) === false) return false;
        return $copy;
    }

    /**
     * Применяет EXIF Orientation к пикселям без зависимости от autoOrientImage().
     * Imagick поворачивает по часовой стрелке (в отличие от GD).
     * При отсутствии orientation API читает EXIF непосредственно из JPEG.
     */
    private function orient($image, $filename): bool
    {
        $orientation = method_exists($image, 'getImageOrientation')
            ? (int) $image->getImageOrientation() : 0;
        if ($orientation < 1 || $orientation > 8) {
            $orientation = ExifOrientation::fromFile($filename);
        }
        $topLeft = defined('Imagick::ORIENTATION_TOPLEFT') ? constant('Imagick::ORIENTATION_TOPLEFT') : 1;
        if ($orientation === 1) return true;

        $transparent = new \ImagickPixel('none');
        switch ($orientation) {
            case 2: // Отражение слева направо.
                $ok = $image->flopImage();
                break;
            case 3: // 180 градусов.
                $ok = $image->rotateImage($transparent, 180);
                break;
            case 4: // Отражение сверху вниз.
                $ok = $image->flipImage();
                break;
            case 5: // Транспонирование (отражение + поворот против часовой).
                $ok = $image->flopImage() !== false &&
                    $image->rotateImage($transparent, -90) !== false;
                break;
            case 6: // Поворот по часовой стрелке.
                $ok = $image->rotateImage($transparent, 90);
                break;
            case 7: // Поперечное отражение (отражение + поворот по часовой).
                $ok = $image->flopImage() !== false &&
                    $image->rotateImage($transparent, 90) !== false;
                break;
            case 8: // Поворот против часовой стрелки.
                $ok = $image->rotateImage($transparent, -90);
                break;
            default:
                return false;
        }
        if ($ok === false) return false;

        // Ориентация записанного EXIF и реальные пиксели должны совпадать.
        return !method_exists($image, 'setImageOrientation') ||
            $image->setImageOrientation($topLeft) !== false;
    }

    /**
     * После физического поворота исправляет также Orientation внутри бинарного EXIF.
     * setImageOrientation() меняет ориентацию изображения, но не гарантирует
     * изменение IFD0 во всех версиях ImageMagick.
     */
    private function normalizeExif($image): bool
    {
        $profiles = $image->getImageProfiles('exif', true);
        if (!is_array($profiles)) return true;
        $profiles = array_change_key_case($profiles, CASE_LOWER);
        if (!isset($profiles['exif'])) return true;
        $original = $profiles['exif'];
        $normalized = ExifOrientation::normalizeProfile($original);
        if ($normalized !== $original && $image->setImageProfile('exif', $normalized) === false) return false;
        return true;
    }

    /** Возвращает объект Imagick (или null до загрузки). */
    public function getImage() { return $this->image; }
    /** Возвращает ширину текущих пикселей. */
    public function getWidth() { return $this->image ? $this->image->getImageWidth() : 1; }
    /** Возвращает высоту текущих пикселей. */
    public function getHeight() { return $this->image ? $this->image->getImageHeight() : 1; }

    /** Определяет семейство цветового пространства пикселей, а не имя ICC-профиля. */
    public function getColorspace(): ?string
    {
        if (!$this->image) return null;
        $value = $this->image->getImageColorspace();
        $groups = array(
            'RGB' => array('RGB', 'SRGB', 'SCRGB', 'LINEARRGB', 'ADOBE98', 'PROPHOTO', 'DISPLAYP3'),
            'CMYK' => array('CMYK'),
            'GRAY' => array('GRAY', 'LINEARGRAY'),
            'LAB' => array('LAB', 'LCH', 'LCHAB'),
            'XYZ' => array('XYZ', 'XYY'),
            'YCBCR' => array('YCBCR'),
            'HSL' => array('HSL', 'HSB', 'HSV'),
        );
        foreach ($groups as $name => $constants) {
            foreach ($constants as $constant) {
                $key = 'Imagick::COLORSPACE_' . $constant;
                if (defined($key) && $value === constant($key)) return $name;
            }
        }
        $icc = $this->getProfile();
        return $icc === null ? 'UNKNOWN' : (IccProfile::colorspace($icc) ?? 'UNKNOWN');
    }

    /** Изменяет размеры изображения с использованием Lanczos. */
    public function resize($width, $height)
    {
        if (!$this->image || $width < 1 || $height < 1) return false;
        return $this->image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, false) !== false;
    }

    /** Обрезает прямоугольную область, сохраняя ICC и прозрачность за краями. */
    public function crop($width, $height, $x, $y): bool
    {
        if (!$this->image || $width < 1 || $height < 1) return false;
        $canvas = $this->canvas($width, $height, null);
        if ($canvas === false) return false;
        if ($canvas->compositeImage($this->image, \Imagick::COMPOSITE_OVER, -$x, -$y) === false) return false;
        if (!$this->copyProfilesTo($canvas)) return false;
        $this->image = $canvas;
        return true;
    }

    /** Создаёт новый холст, по возможности сохраняя исходное цветовое пространство. */
    private function canvas($width, $height, $background)
    {
        $pixel = $background === null ? new \ImagickPixel('transparent')
            : new \ImagickPixel(sprintf('rgb(%d,%d,%d)', $background[0], $background[1], $background[2]));
        $canvas = new \Imagick();
        if ($canvas->newImage($width, $height, $pixel, 'png') === false) return false;
        if ($canvas->setImageDepth($this->image->getImageDepth()) === false) return false;
        if (method_exists($canvas, 'setImageColorspace') && method_exists($this->image, 'getImageColorspace')) {
            $space = $this->image->getImageColorspace();
            // Some ImageMagick versions refuse undefined colorspaces; don't force them.
            if ($space && $canvas->setImageColorspace($space) === false) return false;
        }
        return $canvas;
    }

    /** Вписывает изображение или заполняет область с кадрированием по центру. */
    public function fit($width, $height, $background, $cover): bool
    {
        if (!$this->image || $width < 1 || $height < 1) return false;
        $scale = $cover ? max($width / $this->getWidth(), $height / $this->getHeight())
            : min($width / $this->getWidth(), $height / $this->getHeight());
        $w = max(1, (int) round($this->getWidth() * $scale));
        $h = max(1, (int) round($this->getHeight() * $scale));
        $resized = clone $this->image;
        if ($resized->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1, false) === false) return false;
        $canvas = $this->canvas($width, $height, $background);
        if ($canvas === false) return false;
        if ($canvas->compositeImage($resized, \Imagick::COMPOSITE_OVER,
            (int) round(($width - $w) / 2), (int) round(($height - $h) / 2)) === false) return false;
        if (!$this->copyProfilesTo($canvas)) return false;
        $this->image = $canvas;
        return true;
    }

    /**
     * Переносит ICC на новый холст. При load(..., false) переносит и прочие
     * профили/свойства, чтобы кадрирование не удаляло их неявно.
     */
    private function copyProfilesTo($canvas): bool
    {
        if (!$this->stripMetadata) {
            $profiles = $this->image->getImageProfiles('*', true);
            if (is_array($profiles)) {
                foreach ($profiles as $name => $data) {
                    if ($canvas->setImageProfile($name, $data) === false) return false;
                }
            }
            if (method_exists($this->image, 'getImageProperties') && method_exists($canvas, 'setImageProperty')) {
                $properties = $this->image->getImageProperties('*');
                if (is_array($properties)) {
                    foreach ($properties as $name => $value) {
                        // Some read-only computed properties cannot be changed, ignore those.
                        if (is_string($name) && is_string($value) &&
                            (stripos($name, 'exif:') === 0 || stripos($name, 'xmp:') === 0 ||
                             $name === 'comment' || $name === 'label')) {
                            // EXIF:* can be computed/read-only; the binary EXIF profile is
                            // the authoritative data, so ignore non-writable derived properties.
                            try { $canvas->setImageProperty($name, $value); }
                            catch (\Throwable $ignored) { }
                        }
                    }
                }
            }
            // Новый холст содержит физически ориентированные пиксели.
            // Сброс ориентации обязателен и при копировании EXIF.
            if (method_exists($canvas, 'setImageOrientation')) {
                $topLeft = defined('Imagick::ORIENTATION_TOPLEFT') ? constant('Imagick::ORIENTATION_TOPLEFT') : 1;
                if ($canvas->setImageOrientation($topLeft) === false) return false;
            }
            return true;
        }
        $icc = $this->getProfile();
        return $icc === null || $canvas->setImageProfile('icc', $icc) !== false;
    }

    /** Назначает ICC без конвертации; при replace=false существующий ICC остаётся. */
    public function applyProfile($icc, $replace): bool
    {
        if (!$this->image) return false;
        if ($this->getProfile() !== null && !$replace) return true;
        $copy = clone $this->image;
        if ($copy->setImageProfile('icc', $icc) === false) return false;
        $this->image = $copy;
        return true;
    }

    /** Преобразует цвета из текущего ICC в переданный профиль. */
    public function convertToProfile($icc): bool
    {
        if (!$this->image || $this->getProfile() === null) return false;
        $copy = clone $this->image;
        if ($copy->profileImage('icc', $icc) === false) return false;
        $this->image = $copy;
        return true;
    }

    /** Возвращает байты встроенного ICC или null. */
    public function getProfile()
    {
        if (!$this->image) return null;
        $profiles = $this->image->getImageProfiles('icc', true);
        if (!is_array($profiles)) return null;
        $profiles = array_change_key_case($profiles, CASE_LOWER);
        return isset($profiles['icc']) && $profiles['icc'] !== '' ? $profiles['icc'] : null;
    }
}
