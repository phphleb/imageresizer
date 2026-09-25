<?php
/**
 * @author  Foma Tuturov <fomiash@yandex.ru>
 */

namespace Phphleb\Imageresizer;

class  SimpleImage
{
    protected $filename;
    protected $image;
    protected $image_type;
    protected $image_format;

    // Загрузка исходного файла по ссылке
    function load($filename)
    {
        $image_info = getimagesize($filename);
        if ($image_info === false) {
            return false;
        }
        $this->filename = $filename;
        $this->image_type = $image_info[2];
        $this->image_format = $this->formatFromType($this->image_type);
        $this->image = $this->createFromFile($filename, $this->image_type);
        if (empty($this->image)) {
            return false;
        }
        $this->keepAlpha($this->image);
        return true;
    }

    // Сохранение файла
    function save($result_filename, $image_type, $compression = 100)
    {
        if (empty($this->image)) {
            return false;
        }
        $this->keepAlpha($this->image);
        if ($this->isType($image_type, IMAGETYPE_JPEG, array("jpeg", "jpg"))) {
            return imagejpeg($this->image, $result_filename, $compression);
        } elseif ($this->isType($image_type, IMAGETYPE_GIF, array("gif"))) {
            return imagegif($this->image, $result_filename);
        } elseif ($this->isType($image_type, IMAGETYPE_PNG, array("png"))) {
            return imagepng($this->image, $result_filename);
        } elseif ($this->isType($image_type, IMAGETYPE_WEBP, array("webp"))) {
            return imagewebp($this->image, $result_filename);
        } elseif ($this->isType($image_type, IMAGETYPE_BMP, array("bmp"))) {
            return imagebmp($this->image, $result_filename);
        } elseif ($this->isType($image_type, IMAGETYPE_WBMP, array("wbmp"))) {
            return imagewbmp($this->image, $result_filename);
        } elseif ($this->isAvif($image_type) && function_exists("imageavif")) {
            return imageavif($this->image, $result_filename);
        }
        return false;
    }

    // Вывод изображения в браузер
    function output($image_type = IMAGETYPE_JPEG)
    {
        if (empty($this->image)) {
            return false;
        }
        $this->keepAlpha($this->image);
        if ($this->isType($image_type, IMAGETYPE_JPEG, array("jpeg", "jpg"))) {
            return imagejpeg($this->image);
        } elseif ($this->isType($image_type, IMAGETYPE_GIF, array("gif"))) {
            return imagegif($this->image);
        } elseif ($this->isType($image_type, IMAGETYPE_PNG, array("png"))) {
            return imagepng($this->image);
        } elseif ($this->isType($image_type, IMAGETYPE_WEBP, array("webp"))) {
            return imagewebp($this->image);
        } elseif ($this->isType($image_type, IMAGETYPE_BMP, array("bmp"))) {
            return imagebmp($this->image);
        } elseif ($this->isType($image_type, IMAGETYPE_WBMP, array("wbmp"))) {
            return imagewbmp($this->image);
        } elseif ($this->isAvif($image_type) && function_exists("imageavif")) {
            return imageavif($this->image);
        }
        return false;
    }

    // Возврат данных изображения
    function getImage()
    {
        return $this->image;
    }

    // Ширина исходного изображения
    function getWidth()
    {
        if (empty($this->image)) {
            return 1;
        }
        return imagesx($this->image) ?: 1;
    }

    // Высота исходного изображения
    function getHeight()
    {
        if (empty($this->image)) {
            return 1;
        }
        return imagesy($this->image) ?: 1;
    }

    // Тип исходного файла
    function getImageType()
    {
        return $this->image_type;
    }

    // Расширение (по типу) исходного файла
    function getImageFormat()
    {
        return $this->image_format;
    }

    // Название исходного файла
    function getFilePath()
    {
        return $this->filename;
    }

    // Пропорциональное изменение размера по высоте
    function resizeToHeight($height)
    {
        $ratio = $height / $this->getHeight();
        $width = $this->getWidth() * $ratio;
        $this->resize($width, $height);
    }

    // Пропорциональное изменение размера по ширине
    function resizeToWidth($width)
    {
        $ratio = $width / $this->getWidth();
        $height = $this->getheight() * $ratio;
        $this->resize($width, $height);
    }

    // Пропорциональное изменение размера в процентном отношении
    function scale($scale)
    {
        $width = $this->getWidth() * $scale / 100;
        $height = $this->getheight() * $scale / 100;
        $this->resize($width, $height);
    }

    // Изменение размера по указанным ширине и высоте
    function resize($width, $height)
    {
        $width = (int) ceil($width);
        $height = (int) ceil($height);
        if ($width < 1 || $height < 1 || empty($this->image)) {
            return;
        }
        $new_image = $this->createCanvas($width, $height, null);
        $this->imageCopyResampled($new_image, $this->image, 0, 0, 0, 0, $width, $height, $this->getWidth(), $this->getHeight());
        $this->image = $new_image;
    }

    // Пропорциональное изменение размера с ориентацией по центру и без пустых пространств по сторонам (лишнее обрезается)
    function resizeInCenter($width, $height)
    {
        $width = (int) ceil($width);
        $height = (int) ceil($height);
        if ($width < 1 || $height < 1 || empty($this->image)) {
            return;
        }
        $img_width = $this->getWidth();
        $img_height = $this->getHeight();
        $scale = max($width / $img_width, $height / $img_height);
        $new_width = $img_width * $scale;
        $new_height = $img_height * $scale;
        $x = ($width - $new_width) / 2;
        $y = ($height - $new_height) / 2;
        $new_image = $this->createCanvas($width, $height, null);
        $this->imageCopyResampled($new_image, $this->image, $x, $y, 0, 0, $new_width, $new_height, $img_width, $img_height);
        $this->image = $new_image;
    }

    // Обрезание изображения по указанной области
    function cropBySelectedRegion($width, $height, $x, $y)
    {
        $width = (int) ceil($width);
        $height = (int) ceil($height);
        $x = (int) ceil($x);
        $y = (int) ceil($y);
        if ($width < 1 || $height < 1 || empty($this->image)) {
            return;
        }
        $new_image = $this->createCanvas($width, $height, null);
        $this->imageCopyResampled($new_image, $this->image, 0, 0, $x, $y, $width, $height, $width, $height);
        $this->image = $new_image;
    }

    // Для установки фона в формате RGB (методу resizeAllInCenter)
    function addRgbColor($red, $green, $blue)
    {
        return array($red, $green, $blue);
    }

    // Пропорциональное изменение размера на прозрачном фоне для PNG (с опциональным закрашиванием для всех типов)
    function resizeAllInCenter($width, $height, $background = null)
    {
        $width = (int) ceil($width);
        $height = (int) ceil($height);
        if ($width < 1 || $height < 1 || empty($this->image)) {
            return;
        }
        $img_width = $this->getWidth();
        $img_height = $this->getHeight();
        $scale = min($width / $img_width, $height / $img_height);
        $new_width = $img_width * $scale;
        $new_height = $img_height * $scale;
        $x = ($width - $new_width) / 2;
        $y = ($height - $new_height) / 2;
        $color = $background ? $this->parseColor($background) : null;
        $new_image = $this->createCanvas($width, $height, $color);
        $this->imageCopyResampled($new_image, $this->image, $x, $y, 0, 0, $new_width, $new_height, $img_width, $img_height);
        $this->keepAlpha($new_image);
        $this->image = $new_image;
    }

    protected function createFromFile($filename, $image_type)
    {
        if ($image_type == IMAGETYPE_JPEG) {
            return imagecreatefromjpeg($filename);
        } elseif ($image_type == IMAGETYPE_GIF) {
            return imagecreatefromgif($filename);
        } elseif ($image_type == IMAGETYPE_PNG) {
            return imagecreatefrompng($filename);
        } elseif ($image_type == IMAGETYPE_WEBP) {
            return imagecreatefromwebp($filename);
        } elseif ($image_type == IMAGETYPE_BMP) {
            return imagecreatefrombmp($filename);
        } elseif ($image_type == IMAGETYPE_WBMP) {
            return imagecreatefromwbmp($filename);
        } elseif ($this->isAvif($image_type) && function_exists("imagecreatefromavif")) {
            return imagecreatefromavif($filename);
        }
        return false;
    }

    protected function formatFromType($image_type)
    {
        if ($image_type == IMAGETYPE_WBMP) {
            return "wbmp";
        }
        if ($this->isAvif($image_type)) {
            return "avif";
        }
        return trim(image_type_to_extension($image_type), ".");
    }

    protected function isAvif($image_type)
    {
        if (!defined("IMAGETYPE_AVIF")) {
            return is_string($image_type) && strtolower($image_type) === "avif";
        }
        if ($image_type == IMAGETYPE_AVIF) {
            return true;
        }
        return is_string($image_type) && strtolower($image_type) === "avif";
    }

    protected function isType($image_type, $constant, $names)
    {
        if ($image_type == $constant) {
            return true;
        }
        if (!is_string($image_type)) {
            return false;
        }
        return in_array(strtolower($image_type), $names, true);
    }

    protected function parseColor($background)
    {
        if (is_array($background) && count($background) >= 3) {
            return array((int) $background[0], (int) $background[1], (int) $background[2]);
        }
        if (!is_string($background)) {
            return array(0, 0, 0);
        }
        $hex = ltrim($background, "#");
        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        }
        return array(0, 0, 0);
    }

    protected function createCanvas($width, $height, $rgb)
    {
        $canvas = imagecreatetruecolor($width, $height);
        if ($rgb === null) {
            $this->keepAlpha($canvas);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $transparent);
            return $canvas;
        }
        $color = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $color);
        return $canvas;
    }

    protected function keepAlpha($image)
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    protected function imageCopyResampled($dst_image, $src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h)
    {
        imagecopyresampled(
            $dst_image,
            $src_image,
            intval(round($dst_x)),
            intval(round($dst_y)),
            intval(round($src_x)),
            intval(round($src_y)),
            intval(round($dst_w)),
            intval(round($dst_h)),
            intval(round($src_w)),
            intval(round($src_h))
        );
    }

}
