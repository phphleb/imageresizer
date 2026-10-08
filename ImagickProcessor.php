<?php
namespace Phphleb\Imageresizer;

class ImagickProcessor implements ProcessorInterface
{
    private $image;

    /** Загружает изображение из файла; возвращает true/false. */
    public function load($filename): bool
    {
        $image = new \Imagick();
        $image->readImage($filename);
        if ($image->getNumberImages() > 1) $image->setIteratorIndex(0);
        $this->image = $image;
        return true;
    }

    private function prepare($format, $quality): bool
    {
        if (!$this->image) return false;
        $format = strtolower((string) $format);
        if ($format === 'jpg') $format = 'jpeg';
        if (!in_array($format, array('jpeg', 'png', 'gif', 'webp', 'bmp', 'wbmp', 'avif'), true)) return false;
        $this->image->setImageFormat($format);
        if ($format === 'jpeg' || $format === 'webp') $this->image->setImageCompressionQuality((int) $quality);
        return true;
    }

    /** Сохраняет обработанное изображение в файл в заданном формате и качестве. */
    public function save($filename, $format, $quality)
    {
        if (!$this->prepare($format, $quality)) return false;
        return $this->image->writeImage($filename);
    }

    /** Выводит изображение в поток ответа. */
    public function output($format): bool
    {
        if (!$this->prepare($format, 100)) return false;
        echo $this->image->getImageBlob();
        return true;
    }

    /** Возвращает внутреннее представление текущего изображения. */
    public function getImage() { return $this->image; }
    /** Возвращает ширину текущего изображения в пикселях. */
    public function getWidth() { return $this->image ? $this->image->getImageWidth() : 1; }
    /** Возвращает высоту текущего изображения в пикселях. */
    public function getHeight() { return $this->image ? $this->image->getImageHeight() : 1; }

    /** Изменяет ширину и высоту изображения до указанных значений. */
    public function resize($width, $height)
    {
        if (!$this->image || $width < 1 || $height < 1) return false;
        return $this->image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, false);
    }

    /** Обрезает изображение по заданной прямоугольной области. */
    public function crop($width, $height, $x, $y): bool
    {
        if (!$this->image || $width < 1 || $height < 1) return false;
        // Virtual pixels outside the source are transparent, as with the old GD canvas.
        $canvas = $this->canvas($width, $height, null);
        if (!$canvas->compositeImage($this->image, \Imagick::COMPOSITE_OVER, -$x, -$y)) return false;
        $this->copyIccProfile($canvas);
        $this->image = $canvas;
        return true;
    }

    private function canvas($width, $height, $background): \Imagick
    {
        $pixel = $background === null ? new \ImagickPixel('transparent')
            : new \ImagickPixel(sprintf('rgb(%d,%d,%d)', $background[0], $background[1], $background[2]));
        $canvas = new \Imagick();
        $canvas->newImage($width, $height, $pixel, 'png');
        $canvas->setImageDepth($this->image->getImageDepth());
        return $canvas;
    }

    /** Вписывает изображение в область или заполняет её с обрезанием. */
    public function fit($width, $height, $background, $cover): bool
    {
        if (!$this->image || $width < 1 || $height < 1) return false;
        $scale = $cover ? max($width / $this->getWidth(), $height / $this->getHeight())
                        : min($width / $this->getWidth(), $height / $this->getHeight());
        $w = max(1, (int) round($this->getWidth() * $scale));
        $h = max(1, (int) round($this->getHeight() * $scale));
        $resized = clone $this->image;
        if (!$resized->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1, false)) return false;
        $canvas = $this->canvas($width, $height, $background);
        if (!$canvas->compositeImage($resized, \Imagick::COMPOSITE_OVER,
            (int) round(($width - $w) / 2), (int) round(($height - $h) / 2))) return false;
        $this->copyIccProfile($canvas);
        $this->image = $canvas;
        return true;
    }

    /**
     * Копирует цветовой профиль в новый холст без преобразования пикселей.
     * @param \Imagick $canvas
     * @return void
     */
    private function copyIccProfile($canvas)
    {
        $profile = $this->getProfile();
        if ($profile !== null) {
            $canvas->setImageProfile('icc', $profile);
        }
    }

    /**
     * Назначает ICC без изменения значений пикселей. Без $replace сохраняет исходный профиль.
     * В отличие от profileImage(), setImageProfile() не конвертирует цвета.
     */
    public function applyProfile($icc, $replace): bool
    {
        if (!$this->image) return false;
        $current = $this->getProfile();
        if ($current !== null && !$replace) return true;
        $copy = clone $this->image;
        if (!$copy->setImageProfile('icc', $icc)) return false;
        $this->image = $copy;
        return true;
    }

    /**
     * Конвертирует значения пикселей из существующего ICC в целевой профиль.
     * Использует цветовое преобразование ImageMagick (не простую смену метки).
     */
    public function convertToProfile($icc): bool
    {
        if (!$this->image || $this->getProfile() === null) return false;
        $copy = clone $this->image;
        if (!$copy->profileImage('icc', $icc)) return false;
        $this->image = $copy;
        return true;
    }

    /** Возвращает бинарные данные текущего ICC-профиля или null при его отсутствии. */
    public function getProfile()
    {
        if (!$this->image) return null;
        $profiles = $this->image->getImageProfiles('icc', true);
        return isset($profiles['icc']) ? $profiles['icc'] : null;
    }
}
