<?php
namespace Phphleb\Imageresizer\Src;

/** GD processor. GD has no color-management API: ICC embedding is unsupported. */
class GdImageProcessor implements ProcessorInterface
{
    private $image;
    private $format;
    /** GD преобразует загруженное изображение в RGB-пиксели. */
    public function getColorspace(): ?string { return $this->image ? 'RGB' : null; }

    /** Загружает изображение из файла; возвращает true/false. */
    public function load($filename, $stripMetadata = true): bool
    {
        // Параметр действует только для Imagick. GD всегда записывает файл без
        // исходных EXIF/GPS/XMP, как в прежней версии библиотеки.
        $info = @getimagesize($filename);
        if (!$info) return false;
        $format = strtolower(image_type_to_extension($info[2], false));
        if ($format === 'jpg') $format = 'jpeg';
        $fn = 'imagecreatefrom' . $format;
        if (!function_exists($fn)) return false;
        $image = @$fn($filename);
        if (!$image) return false;
        $this->alpha($image);
        // EXIF Orientation — необходимая инструкция по отображению изображения,
        // а не мусорные метаданные. Перед записью без EXIF переносим её в пиксели.
        $orientation = ExifOrientation::fromFile($filename);
        $oriented = $this->orient($image, $orientation);
        if ($oriented === false) return false;
        $this->image = $oriented;
        $this->format = $format;
        return true;
    }

    private function alpha($image)
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    /** Применяет поворот/отражение EXIF Orientation к GD-пикселям (все значения 1–8). */
    private function orient($image, $orientation)
    {
        if ($orientation === 1) return $image;
        // Для ориентаций 2,4,5,7 требуется отражение.
        if (in_array($orientation, array(2, 4, 5, 7), true)) {
            $direction = $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL;
            if (!function_exists('imageflip') || imageflip($image, $direction) === false) return false;
        }
        $degrees = array(3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90);
        if (!isset($degrees[$orientation])) return $image;
        if (!function_exists('imagerotate')) return false;
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, $degrees[$orientation], $transparent);
        if ($rotated === false) return false;
        $this->alpha($rotated);
        return $rotated;
    }

    /** Сохраняет обработанное изображение в файл в заданном формате и качестве. */
    public function save($filename, $format, $quality)
    {
        $format = strtolower((string) $format);
        if ($format === 'jpg') $format = 'jpeg';
        if (!$this->image) return false;
        $fn = 'image' . $format;
        if (!function_exists($fn)) return false;
        if (in_array($format, array('jpeg', 'webp', 'avif'), true)) return @$fn($this->image, $filename, (int) $quality);
        return @$fn($this->image, $filename);
    }

    /** Выводит изображение в поток ответа. */
    public function output($format) { return $this->save(null, $format, 100); }
    /** Возвращает внутреннее представление текущего изображения. */
    public function getImage() { return $this->image; }
    /** Возвращает ширину текущего изображения в пикселях. */
    public function getWidth() { return $this->image ? imagesx($this->image) : 1; }
    /** Возвращает высоту текущего изображения в пикселях. */
    public function getHeight() { return $this->image ? imagesy($this->image) : 1; }

    private function canvas($width, $height, $rgb = null)
    {
        $canvas = imagecreatetruecolor($width, $height);
        $this->alpha($canvas);
        if ($rgb === null) $color = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        else $color = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $color);
        return $canvas;
    }

    private function copy($canvas, $x, $y, $sx, $sy, $w, $h, $sw, $sh)
    {
        return imagecopyresampled($canvas, $this->image, (int) round($x), (int) round($y),
            (int) round($sx), (int) round($sy), (int) round($w), (int) round($h),
            (int) round($sw), (int) round($sh));
    }

    /** Изменяет ширину и высоту изображения до указанных значений. */
    public function resize($width, $height): bool
    {
        if (!$this->image || $width < 1 || $height < 1) return false;
        $canvas = $this->canvas($width, $height);
        if (!$this->copy($canvas, 0, 0, 0, 0, $width, $height, $this->getWidth(), $this->getHeight())) return false;
        $this->image = $canvas;
        return true;
    }

    /** Обрезает изображение по заданной прямоугольной области. */
    public function crop($width, $height, $x, $y): bool
    {
        if (!$this->image || $width < 1 || $height < 1) return false;
        $canvas = $this->canvas($width, $height);
        if (!$this->copy($canvas, 0, 0, $x, $y, $width, $height, $width, $height)) return false;
        $this->image = $canvas;
        return true;
    }

    /** Вписывает изображение в область или заполняет её с обрезанием. */
    public function fit($width, $height, $background, $cover): bool
    {
        if (!$this->image || $width < 1 || $height < 1) return false;
        $scale = $cover ? max($width / $this->getWidth(), $height / $this->getHeight())
                        : min($width / $this->getWidth(), $height / $this->getHeight());
        $w = $this->getWidth() * $scale;
        $h = $this->getHeight() * $scale;
        $canvas = $this->canvas($width, $height, $background);
        if (!$this->copy($canvas, ($width - $w) / 2, ($height - $h) / 2,
            0, 0, $w, $h, $this->getWidth(), $this->getHeight())) return false;
        $this->image = $canvas;
        return true;
    }

    /** Назначает ICC без преобразования цвета; replace разрешает замену существующей метки. */
    public function applyProfile($icc, $replace): bool
    { return false; }
    /** Возвращает бинарные данные текущего ICC либо null. */
    public function getProfile() { return null; }
    /** Преобразует пиксели из исходного встроенного ICC в указанный целевой ICC. */
    public function convertToProfile($icc): bool
    { return false; }
}
