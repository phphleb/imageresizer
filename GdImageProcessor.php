<?php
namespace Phphleb\Imageresizer;

/** GD processor. GD has no color-management API: ICC embedding is unsupported. */
class GdImageProcessor implements ProcessorInterface
{
    private $image;
    private $format;

    /** Загружает изображение из файла; возвращает true/false. */
    public function load($filename): bool
    {
        $info = @getimagesize($filename);
        if (!$info) return false;
        $format = strtolower(image_type_to_extension($info[2], false));
        if ($format === 'jpg') $format = 'jpeg';
        $fn = 'imagecreatefrom' . $format;
        if (!function_exists($fn)) return false;
        $image = @$fn($filename);
        if (!$image) return false;
        $this->image = $image;
        $this->format = $format;
        $this->alpha($image);
        return true;
    }

    private function alpha($image)
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
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

    private function copy($canvas, $x, $y, $sx, $sy, $w, $h, $sw, $sh): bool
    {
        return imagecopyresampled($canvas, $this->image, (int) round($x), (int) round($y),
            (int) round($sx), (int) round($sy), (int) round($w), (int) round($h),
            (int) round($sw), (int) round($sh));
    }

    /** Изменяет ширину и высоту изображения до указанных значений. */
    public function resize($width, $height)
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
    {
        return false;
    }

    /** Возвращает бинарные данные текущего ICC либо null. */
    public function getProfile()
    {
        return null;
    }

    /** Преобразует пиксели из исходного встроенного ICC в указанный целевой ICC. */
    public function convertToProfile($icc): bool
    {
        return false;
    }
}
