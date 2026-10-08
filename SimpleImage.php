<?php
/**
 * @author Foma Tuturov <fomiash@yandex.ru>
 */
namespace Phphleb\Imageresizer;

require_once __DIR__ . '/ProcessorInterface.php';
require_once __DIR__ . '/GdImageProcessor.php';
require_once __DIR__ . '/ImagickProcessor.php';
require_once __DIR__ . '/ImageError.php';

/** Обертка для обработки изображений через Imagick или GD. */
class SimpleImage
{
    const PROCESSOR_AUTO = 'auto';
    const PROCESSOR_GD = 'gd';
    const PROCESSOR_IMAGICK = 'imagick';

    /** Путь к ICC-профилю sRGB IEC61966-2.1 из комплекта библиотеки. */
    const PROFILE_SRGB = __DIR__ . '/profiles/sRGB-IEC61966-2.1.icc';

    protected $filename;
    protected $image;
    protected $image_type;
    protected $image_format;

    private $processor;
    private $version = self::PROCESSOR_AUTO;
    private $profile = self::PROFILE_SRGB;
    private $replaceProfile = false;
    private $error;

    /** Возвращает последнюю ошибку (ImageError) или null, если её нет. */
    public function getError(): ?\Exception
    {
        return $this->error;
    }

    /** Возвращает выбранный обработчик, а после загрузки — используемый: auto, gd, imagick. */
    public function getProcessorVersion(): string
    {
        if ($this->processor instanceof ImagickProcessor) {
            return self::PROCESSOR_IMAGICK;
        }
        if ($this->processor instanceof GdImageProcessor) {
            return self::PROCESSOR_GD;
        }
        return $this->version;
    }

    /**
     * Выбирает обработчик перед load(). AUTO предпочитает Imagick, затем GD.
     * Если принудительно выбранное расширение недоступно, возвращает false и сохраняет ошибку.
     * Изменять выбранный обработчик у уже загруженного изображения нельзя.
     *
     * @param string $version Одна из констант PROCESSOR_AUTO, PROCESSOR_GD, PROCESSOR_IMAGICK.
     * @return bool
     */
    public function setProcessorVersion(string $version): bool
    {
        if (!in_array($version, array(self::PROCESSOR_AUTO, self::PROCESSOR_GD, self::PROCESSOR_IMAGICK), true)) {
            return $this->fail(ImageError::INVALID_ARGUMENT, 'Unknown image processor');
        }
        if ($this->processor && $version !== $this->version) {
            return $this->fail(ImageError::INVALID_ARGUMENT, 'Cannot change the image processor after loading an image');
        }
        if ($version !== self::PROCESSOR_AUTO && !$this->available($version)) {
            return $this->fail(ImageError::BACKEND_UNAVAILABLE, 'PHP extension is not available: ' . $version);
        }
        $this->version = $version;
        $this->error = null;
        return true;
    }

    /**
     * Назначает ICC-профиль БЕЗ изменения значений пикселей.
     * При $replace=false сохраняет уже встроенный профиль; при true заменяет его метку.
     * Это НЕ конвертация цветов: для пересчёта пикселей используйте convertToProfile().
     * В GD ICC не записывается; после загрузки через GD метод вернёт false.
     * Настройку можно задать до load(), тогда она применяется при загрузке через Imagick.
     *
     * @param string $profile PROFILE_SRGB или путь к читаемому ICC-файлу.
     * @param bool $replace Заменить существующий ICC без конвертации цветов.
     * @return bool
     */
    public function setProfile(string $profile = self::PROFILE_SRGB, bool $replace = false): bool
    {
        $icc = $this->readProfile($profile);
        if ($icc === false) {
            return false;
        }
        if ($this->processor) {
            if (!$this->applyProfileTo($this->processor, $icc, (bool) $replace)) {
                return false;
            }
            $this->image = $this->processor->getImage();
        }
        $this->profile = $profile;
        $this->replaceProfile = (bool) $replace;
        $this->error = null;
        return true;
    }

    /**
     * Конвертирует цвета пикселей из встроенного исходного ICC-профиля в указанный целевой.
     * В отличие от setProfile(), изменяет значения пикселей, сохраняя по возможности их
     * визуальное представление. Требуются загруженное изображение, Imagick и исходный ICC.
     * При загрузке файла без ICC библиотека по умолчанию назначает ему sRGB.
     * Если это неверное предположение, сначала назначьте правильный ICC через setProfile().
     * Исходный файл на диске не изменяется до вызова save().
     *
     * @param string $profile PROFILE_SRGB или путь к читаемому целевому ICC-файлу.
     * @return bool
     */
    public function convertToProfile(string $profile = self::PROFILE_SRGB): bool
    {
        if (!$this->processor) {
            return $this->fail(ImageError::PROCESSING_FAILED, 'Load an image before converting its color profile');
        }
        if (!($this->processor instanceof ImagickProcessor)) {
            return $this->fail(ImageError::PROFILE_UNSUPPORTED, 'ICC color conversion requires Imagick');
        }
        $icc = $this->readProfile($profile);
        if ($icc === false) {
            return false;
        }
        try {
            if ($this->processor->getProfile() === null) {
                return $this->fail(ImageError::SOURCE_PROFILE_MISSING, 'The source image has no embedded ICC profile');
            }
            if (!$this->processor->convertToProfile($icc)) {
                return $this->fail(ImageError::PROCESSING_FAILED, 'Failed to convert the ICC color profile');
            }
            $this->image = $this->processor->getImage();
            $this->error = null;
            return true;
        } catch (\Throwable $e) {
            return $this->fail(ImageError::PROCESSING_FAILED, 'ICC color conversion failed: ' . $e->getMessage());
        }
    }

    /**
     * Загружает изображение из файла или URL. По умолчанию использует Imagick, иначе GD.
     * При использовании Imagick назначает sRGB при отсутствии встроенного ICC; пиксели не меняются.
     * При ошибке возвращает false, описание доступно через getError().
     *
     * @param string $filename Путь или URL изображения.
     * @return bool
     */
    public function load(string $filename): bool
    {
        if ($filename === '') {
            return $this->fail(ImageError::INVALID_ARGUMENT, 'Image filename must be a non-empty string');
        }
        $processor = $this->resolveProcessor();
        if ($processor === false) {
            return false;
        }
        try {
            if (!$processor->load($filename)) {
                return $this->fail(ImageError::LOAD_FAILED, 'Failed to load image: ' . $filename);
            }
            if ($processor instanceof ImagickProcessor) {
                $icc = $this->readProfile($this->profile);
                if ($icc === false || !$this->applyProfileTo($processor, $icc, $this->replaceProfile)) {
                    return false;
                }
            }
            $info = @getimagesize($filename);
            $this->processor = $processor;
            $this->image = $processor->getImage();
            $this->filename = $filename;
            $this->image_type = $info ? $info[2] : null;
            $this->image_format = $info ? $this->formatFromType($info[2]) : null;
            $this->error = null;
            return true;
        } catch (\Throwable $e) {
            return $this->fail(ImageError::LOAD_FAILED, 'Failed to load image: ' . $e->getMessage());
        }
    }

    /**
     * Сохраняет изображение в файл. Поддерживает имена форматов (jpeg, png и др.)
     * и числовые константы IMAGETYPE_*. Для JPEG и WebP $compression задаёт качество.
     * Возвращает true/false; при неудаче используйте getError().
     *
     * @return bool
     */
    public function save($result_filename, $image_type, $compression = 100): bool
    {
        if (!$this->processor) {
            return $this->fail(ImageError::SAVE_FAILED, 'Load an image before saving it');
        }
        $format = $this->normalizeFormat($image_type);
        if ($format === false || !is_string($result_filename) || $result_filename === '') {
            return $this->fail(ImageError::INVALID_ARGUMENT, 'Invalid output filename or image format');
        }
        try {
            if (!$this->processor->save($result_filename, $format, $compression)) {
                return $this->fail(ImageError::SAVE_FAILED, 'Failed to save the image');
            }
            $this->error = null;
            return true;
        } catch (\Throwable $e) {
            return $this->fail(ImageError::SAVE_FAILED, 'Failed to save the image: ' . $e->getMessage());
        }
    }

    /**
     * Выводит изображение в поток ответа (по умолчанию JPEG).
     * Возвращает true/false, ошибку можно получить через getError().
     *
     * @return bool
     */
    public function output($image_type = IMAGETYPE_JPEG): bool
    {
        if (!$this->processor) {
            return $this->fail(ImageError::SAVE_FAILED, 'Load an image before outputting it');
        }
        $format = $this->normalizeFormat($image_type);
        if ($format === false) {
            return $this->fail(ImageError::INVALID_ARGUMENT, 'Unsupported output image format');
        }
        try {
            if (!$this->processor->output($format)) {
                return $this->fail(ImageError::SAVE_FAILED, 'Failed to output the image');
            }
            $this->error = null;
            return true;
        } catch (\Throwable $e) {
            return $this->fail(ImageError::SAVE_FAILED, 'Failed to output the image: ' . $e->getMessage());
        }
    }

    /** Возвращает внутренний объект GD или Imagick (до загрузки — null). */
    public function getImage()
    {
        return $this->processor ? $this->processor->getImage() : null;
    }

    /** Возвращает ширину изображения в пикселях (до загрузки — 1). */
    public function getWidth()
    {
        return $this->processor ? $this->processor->getWidth() : 1;
    }

    /** Возвращает высоту изображения в пикселях (до загрузки — 1). */
    public function getHeight()
    {
        return $this->processor ? $this->processor->getHeight() : 1;
    }

    /** Возвращает числовой IMAGETYPE_* исходного изображения. */
    public function getImageType()
    {
        return $this->image_type;
    }

    /** Возвращает расширение формата исходного изображения, например jpeg или png. */
    public function getImageFormat()
    {
        return $this->image_format;
    }

    /** Возвращает путь или URL последнего успешно загруженного изображения. */
    public function getFilePath()
    {
        return $this->filename;
    }

    /** Пропорционально меняет высоту. Как в прежнем API, метод ничего не возвращает. */
    public function resizeToHeight($height)
    {
        if (!is_numeric($height)) {
            $this->fail(ImageError::INVALID_ARGUMENT, 'Image height must be numeric');
            return;
        }
        $this->resize($this->getWidth() * $height / $this->getHeight(), $height);
    }

    /** Пропорционально меняет ширину. Как в прежнем API, метод ничего не возвращает. */
    public function resizeToWidth($width)
    {
        if (!is_numeric($width)) {
            $this->fail(ImageError::INVALID_ARGUMENT, 'Image width must be numeric');
            return;
        }
        $this->resize($width, $this->getHeight() * $width / $this->getWidth());
    }

    /** Пропорционально изменяет размер на заданный процент; результат — через getImage(). */
    public function scale($scale)
    {
        if (!is_numeric($scale)) {
            $this->fail(ImageError::INVALID_ARGUMENT, 'Image scale must be numeric');
            return;
        }
        $this->resize($this->getWidth() * $scale / 100, $this->getHeight() * $scale / 100);
    }

    /** Изменяет ширину и высоту без сохранения пропорций. Возвращает void. */
    public function resize($width, $height)
    {
        $this->run('resize', array($width, $height));
    }

    /** Заполняет заданную область изображением с обрезанием лишнего по центру. Возвращает void. */
    public function resizeInCenter($width, $height)
    {
        $this->run('fit', array($width, $height, null, true));
    }

    /** Вырезает область заданного размера с координатами её левого верхнего угла. Возвращает void. */
    public function cropBySelectedRegion($width, $height, $x, $y)
    {
        $this->run('crop', array($width, $height, $x, $y));
    }

    /** Возвращает массив RGB для фона в resizeAllInCenter(). */
    public function addRgbColor($red, $green, $blue): array
    {
        return array($red, $green, $blue);
    }

    /** Вписывает изображение целиком и центрирует его на прозрачном или цветном фоне. */
    public function resizeAllInCenter($width, $height, $background = null)
    {
        $this->run('fit', array($width, $height, $background === null ? null : $this->parseColor($background), false));
    }

    private function available($version): bool
    {
        if ($version === self::PROCESSOR_IMAGICK) {
            return extension_loaded('imagick') && class_exists('Imagick');
        }
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    private function resolveProcessor()
    {
        $version = $this->version;
        if ($version === self::PROCESSOR_AUTO) {
            $version = $this->available(self::PROCESSOR_IMAGICK) ? self::PROCESSOR_IMAGICK : self::PROCESSOR_GD;
        }
        if (!$this->available($version)) {
            return $this->fail(ImageError::BACKEND_UNAVAILABLE, 'No available PHP image processor: ' . $version);
        }
        return $version === self::PROCESSOR_IMAGICK ? new ImagickProcessor() : new GdImageProcessor();
    }

    private function readProfile($profile)
    {
        if (!is_string($profile) || $profile === '') {
            return $this->fail(ImageError::INVALID_ARGUMENT, 'ICC profile path must be a non-empty string');
        }
        if (!is_file($profile) || !is_readable($profile)) {
            return $this->fail(ImageError::PROFILE_NOT_FOUND, 'ICC profile file not found: ' . $profile);
        }
        $icc = @file_get_contents($profile);
        if ($icc === false || strlen($icc) < 132 || substr($icc, 36, 4) !== 'acsp') {
            return $this->fail(ImageError::PROFILE_NOT_FOUND, 'Invalid ICC profile file: ' . $profile);
        }
        $header = unpack('Nsize', substr($icc, 0, 4));
        if ($header['size'] < 132 || $header['size'] > strlen($icc)) {
            return $this->fail(ImageError::PROFILE_NOT_FOUND, 'Invalid ICC profile length: ' . $profile);
        }
        return $icc;
    }

    private function applyProfileTo($processor, $icc, $replace): bool
    {
        if (!($processor instanceof ImagickProcessor)) {
            return $this->fail(ImageError::PROFILE_UNSUPPORTED, 'ICC profiles require Imagick; GD cannot embed ICC profiles');
        }
        try {
            if (!$processor->applyProfile($icc, $replace)) {
                return $this->fail(ImageError::PROCESSING_FAILED, 'Failed to assign the ICC profile');
            }
            return true;
        } catch (\Throwable $e) {
            return $this->fail(ImageError::PROCESSING_FAILED, 'Failed to assign the ICC profile: ' . $e->getMessage());
        }
    }

    private function normalizeFormat($type)
    {
        $types = array(
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WBMP => 'wbmp',
            IMAGETYPE_BMP => 'bmp',
            IMAGETYPE_WEBP => 'webp'
        );
        if (defined('IMAGETYPE_AVIF')) {
            $types[constant('IMAGETYPE_AVIF')] = 'avif';
        }
        if (is_int($type)) {
            return $types[$type] ?? false;
        }
        if (!is_string($type)) {
            return false;
        }
        $format = strtolower($type);
        if ($format === 'jpg') {
            $format = 'jpeg';
        }
        return in_array($format, $types, true) ? $format : false;
    }

    private function run($method, $arguments)
    {
        if (!$this->processor) {
            $this->fail(ImageError::PROCESSING_FAILED, 'Load an image before processing it');
            return;
        }
        foreach ($arguments as $index => $value) {
            if ($method === 'fit' && $index >= 2) {
                break;
            }
            if ($method === 'crop' && $index >= 4) {
                break;
            }
            if (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > PHP_INT_MAX) {
                $this->fail(ImageError::INVALID_ARGUMENT, 'Invalid image dimension or crop coordinate');
                return;
            }
            $arguments[$index] = (int) ceil((float) $value);
        }
        if ($arguments[0] < 1 || $arguments[1] < 1) {
            $this->fail(ImageError::INVALID_ARGUMENT, 'Image dimensions must be positive');
            return;
        }
        try {
            if (!call_user_func_array(array($this->processor, $method), $arguments)) {
                $this->fail(ImageError::PROCESSING_FAILED, 'Image processing failed');
                return;
            }
            $this->image = $this->processor->getImage();
            $this->error = null;
        } catch (\Throwable $e) {
            $this->fail(ImageError::PROCESSING_FAILED, 'Image processing failed: ' . $e->getMessage());
        }
    }

    private function fail($code, $message): bool
    {
        $this->error = new ImageError($code, $message);
        return false;
    }

    /** Возвращает расширение по типу исходного файла. */
    protected function formatFromType($image_type)
    {
        if ($this->isAvif($image_type)) {
            return 'avif';
        }
        if ($image_type == IMAGETYPE_WBMP) {
            return 'wbmp';
        }
        return trim(image_type_to_extension($image_type), '.');
    }

    /** Определяет, соответствует ли значение типу AVIF. */
    protected function isAvif($image_type): bool
    {
        return (defined('IMAGETYPE_AVIF') && $image_type == constant('IMAGETYPE_AVIF'))
            || (is_string($image_type) && strtolower($image_type) === 'avif');
    }

    /** Сравнивает формат по числовому типу или строковому имени. */
    protected function isType($image_type, $constant, $names): bool
    {
        return $image_type == $constant || (is_string($image_type) && in_array(strtolower($image_type), $names, true));
    }

    /** Преобразует шестнадцатеричный цвет или массив RGB в три числовых компонента. */
    protected function parseColor($background): array
    {
        if (is_array($background) && count($background) >= 3) {
            return array((int) $background[0], (int) $background[1], (int) $background[2]);
        }
        if (!is_string($background)) {
            return array(0, 0, 0);
        }
        $hex = ltrim($background, '#');
        if (preg_match('/^[0-9a-f]{3}$/i', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return array(0, 0, 0);
        }
        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
