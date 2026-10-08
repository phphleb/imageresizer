<?php
namespace Phphleb\Imageresizer\Src;

/** Вспомогательное чтение заголовка ICC и его текстового описания без расширений PHP. */
final class IccProfile
{
    /** Возвращает семейство цветов, заданное внутри ICC, либо null. */
    public static function colorspace($data): ?string
    {
        if (!self::valid($data)) return null;
        $space = substr($data, 16, 4);
        $map = array('RGB ' => 'RGB', 'CMYK' => 'CMYK', 'GRAY' => 'GRAY', 'Lab ' => 'LAB',
            'XYZ ' => 'XYZ', 'Luv ' => 'LUV', 'YCbr' => 'YCBCR', 'Yxy ' => 'YXY', 'HSV ' => 'HSV',
            'HLS ' => 'HSL');
        return isset($map[$space]) ? $map[$space] : 'OTHER';
    }

    /** Возвращает наименование ICC из тегов desc/mluc; если имени нет, возвращает null. */
    public static function name($data): ?string
    {
        if (!self::valid($data)) return null;
        $size = strlen($data);
        $count = self::number($data, 128);
        if ($count === null || $count > (int) floor(($size - 132) / 12)) return null;
        $entries = array();
        for ($i = 0; $i < $count; $i++) {
            $pos = 132 + $i * 12;
            $tag = substr($data, $pos, 4);
            if ($tag !== 'desc' && $tag !== 'mluc') continue;
            $offset = self::number($data, $pos + 4);
            $length = self::number($data, $pos + 8);
            if ($offset === null || $length === null || $offset > $size || $length > $size - $offset) continue;
            $entries[$tag] = substr($data, $offset, $length);
        }
        // Prefer the internationalized v4 description; fall back to v2 ASCII.
        foreach (array('mluc', 'desc') as $tag) {
            if (!isset($entries[$tag])) continue;
            $block = $entries[$tag];
            if (substr($block, 0, 4) === 'desc') {
                $length = self::number($block, 8);
                if ($length !== null && $length > 0 && 12 + $length <= strlen($block)) {
                    $name = self::clean(substr($block, 12, $length));
                    if ($name !== null) return $name;
                }
            }
            if (substr($block, 0, 4) === 'mluc' && strlen($block) >= 28) {
                $count = self::number($block, 8);
                $recordSize = self::number($block, 12);
                if ($count === null || $recordSize === null || $recordSize < 12) continue;
                $count = min($count, (int) floor((strlen($block) - 16) / $recordSize));
                for ($i = 0; $i < $count; $i++) {
                    $pos = 16 + $i * $recordSize;
                    $length = self::number($block, $pos + 4);
                    $offset = self::number($block, $pos + 8);
                    if ($length === null || $offset === null || $length % 2 !== 0 ||
                        $offset > strlen($block) || $length > strlen($block) - $offset) continue;
                    $raw = substr($block, $offset, $length);
                    if (function_exists('iconv')) {
                        $decoded = @iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
                    } elseif (function_exists('mb_convert_encoding')) {
                        $decoded = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
                    } else {
                        $decoded = self::basicUtf16($raw);
                    }
                    if (is_string($decoded) && ($name = self::clean($decoded)) !== null) return $name;
                }
            }
        }
        return null;
    }

    /** Проверяет минимальную структуру ICC, не подменяя собой полную валидацию цветового профиля. */
    public static function valid($data): bool
    {
        if (!is_string($data) || strlen($data) < 132 || substr($data, 36, 4) !== 'acsp') return false;
        $length = self::number($data, 0);
        return $length !== null && $length >= 132 && $length <= strlen($data);
    }

    private static function number($bytes, $offset): ?int
    {
        if ($offset < 0 || $offset + 4 > strlen($bytes)) return null;
        $result = unpack('Nnumber', substr($bytes, $offset, 4));
        return (int) $result['number'];
    }

    private static function clean($value): ?string
    {
        $value = trim(str_replace("\0", '', $value));
        $value = preg_replace('/[\x00-\x1f\x7f]/', '', $value);
        return $value === '' ? null : $value;
    }

    private static function basicUtf16($data): string
    {
        $result = '';
        for ($i = 0; $i + 1 < strlen($data); $i += 2) {
            $code = unpack('n', substr($data, $i, 2))[1];
            if ($code <= 0x7f) $result .= chr($code);
            elseif ($code <= 0x7ff) $result .= chr(0xc0 | ($code >> 6)) . chr(0x80 | ($code & 63));
            elseif ($code >= 0xd800 && $code <= 0xdfff) continue;
            else $result .= chr(0xe0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 63)) . chr(0x80 | ($code & 63));
        }
        return $result;
    }
}
