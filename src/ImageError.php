<?php
namespace Phphleb\Imageresizer\Src;

/** Описание последней ошибки обработки без обязательного выбрасывания исключения. */
class ImageError extends \Exception
{
    const BACKEND_UNAVAILABLE = 1;
    const LOAD_FAILED = 2;
    const SAVE_FAILED = 3;
    const PROCESSING_FAILED = 4;
    const PROFILE_NOT_FOUND = 5;
    const PROFILE_UNSUPPORTED = 6;
    const SOURCE_PROFILE_MISSING = 7;
    const INVALID_ARGUMENT = 8;
    const PROFILE_COLORSPACE_MISMATCH = 9;

    /** Принимает код и сообщение на английском. */
    public function __construct($code, $message)
    {
        parent::__construct($message, $code);
    }

    /** Текст ошибки для вывода в лог. */
    public function __toString() { return $this->getMessage(); }
}
