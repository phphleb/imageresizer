<?php
namespace Phphleb\Imageresizer\Src;

interface ProcessorInterface
{
    /** Загружает изображение из файла; возвращает true/false. */
    public function load($filename, $stripMetadata = true);
    /** Сохраняет обработанное изображение в файл в заданном формате и качестве. */
    public function save($filename, $format, $quality);
    /** Выводит изображение в поток ответа. */
    public function output($format);
    /**
     * Возвращает внутреннее представление текущего изображения.
     *
     * @deprecated Используйте getImagick() или getGd().
     */
    public function getImage();
    /**
     * Возвращает объект Imagick текущего изображения.
     *
     * @throws ImageError Если текущий обработчик — GD.
     */
    public function getImagick();
    /**
     * Возвращает GD-ресурс или объект GdImage текущего изображения.
     *
     * @throws ImageError Если текущий обработчик — Imagick.
     */
    public function getGd();
    /** Возвращает ширину текущего изображения в пикселях. */
    public function getWidth();
    /** Возвращает высоту текущего изображения в пикселях. */
    public function getHeight();
    /** Изменяет ширину и высоту изображения до указанных значений. */
    public function resize($width, $height);
    /** Обрезает изображение по заданной прямоугольной области. */
    public function crop($width, $height, $x, $y);
    /** Вписывает изображение в область или заполняет её с обрезанием. */
    public function fit($width, $height, $background, $cover);
    /** Назначает ICC без преобразования цвета; replace разрешает замену существующей метки. */
    public function applyProfile($icc, $replace);
    /** Преобразует пиксели из исходного встроенного ICC в указанный целевой ICC. */
    public function convertToProfile($icc);
    /** Возвращает бинарные данные текущего ICC либо null. */
    public function getProfile();
    /** Определяет семейство цветового пространства декодированных пикселей. */
    public function getColorspace();
}
