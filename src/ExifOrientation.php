<?php
namespace Phphleb\Imageresizer\Src;

/** Читает EXIF Orientation из JPEG без обязательной зависимости от ext-exif. */
final class ExifOrientation
{
    /**
     * Возвращает число от 1 до 8. Значение 1 означает отсутствие необходимого поворота.
     * Другие EXIF-данные библиотека не читает и не сохраняет с помощью этого класса.
     */
    public static function fromFile($filename): int
    {
        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($filename, 'IFD0', true, false);
                if (is_array($exif)) {
                    $value = isset($exif['IFD0']['Orientation']) ? $exif['IFD0']['Orientation']
                        : (isset($exif['Orientation']) ? $exif['Orientation'] : null);
                    if (is_numeric($value) && (int) $value >= 1 && (int) $value <= 8) {
                        return (int) $value;
                    }
                }
            } catch (\Throwable $ignored) {
                // Необязательное расширение EXIF может не поддерживать данный файл.
            }
        }

        // JPEG APP1 находится перед пиксельными данными. Лимит позволяет избежать
        // загрузки всего большого файла в память только ради Orientation.
        $bytes = @file_get_contents($filename, false, null, 0, 1048576);
        if (!is_string($bytes) || substr($bytes, 0, 2) !== "\xff\xd8") return 1;
        $length = strlen($bytes);
        $offset = 2;
        while ($offset + 4 <= $length) {
            if (ord($bytes[$offset]) !== 0xff) break;
            while ($offset < $length && ord($bytes[$offset]) === 0xff) $offset++;
            if ($offset >= $length) break;
            $marker = ord($bytes[$offset++]);
            if ($marker === 0xda || $marker === 0xd9) break; // SOS / EOI.
            if ($marker === 0x01 || ($marker >= 0xd0 && $marker <= 0xd7)) continue;
            if ($offset + 2 > $length) break;
            $segmentLength = self::short($bytes, $offset, true);
            if ($segmentLength === null || $segmentLength < 2 || $offset + $segmentLength > $length) break;
            if ($marker === 0xe1 && substr($bytes, $offset + 2, 6) === "Exif\0\0") {
                $result = self::parseTiff($bytes, $offset + 8, $offset + $segmentLength);
                if ($result !== null) return $result;
            }
            $offset += $segmentLength;
        }
        return 1;
    }

    /**
     * Исправляет EXIF Orientation на 1 в бинарном профиле после физического поворота.
     * Остальные поля (в том числе EXIF, GPS, автор, камера) остаются без изменений.
     * Незнакомый или повреждённый EXIF возвращается без изменений.
     */
    public static function normalizeProfile(string $profile): string
    {
        $start = substr($profile, 0, 6) === "Exif\0\0" ? 6 : 0;
        $entry = self::orientationEntry($profile, $start, strlen($profile));
        if ($entry === null) return $profile;
        $big = substr($profile, $start, 2) === 'MM';
        return substr_replace($profile, pack($big ? 'n' : 'v', 1), $entry, 2);
    }

    /** Возвращает ориентацию бинарного EXIF, если она корректна. */
    public static function fromProfile(string $profile): ?int
    {
        $start = substr($profile, 0, 6) === "Exif\0\0" ? 6 : 0;
        return self::parseTiff($profile, $start, strlen($profile));
    }

    private static function parseTiff($data, $start, $end): ?int
    {
        $entry = self::orientationEntry($data, $start, $end);
        if ($entry === null) return null;
        $value = self::short($data, $entry, substr($data, $start, 2) === 'MM');
        return $value !== null && $value >= 1 && $value <= 8 ? $value : null;
    }

    /** Адрес двух байт значения Orientation в TIFF IFD0 либо null. */
    private static function orientationEntry($data, $start, $end): ?int
    {
        if ($start + 8 > $end) return null;
        $order = substr($data, $start, 2);
        if ($order !== 'II' && $order !== 'MM') return null;
        $big = $order === 'MM';
        if (self::short($data, $start + 2, $big) !== 42) return null;
        $ifd = self::long($data, $start + 4, $big);
        if ($ifd === null || $ifd > $end - $start - 2) return null;
        $pos = $start + $ifd;
        $count = self::short($data, $pos, $big);
        if ($count === null || $count > (int) floor(($end - $pos - 2) / 12)) return null;
        for ($i = 0; $i < $count; $i++) {
            $entry = $pos + 2 + 12 * $i;
            if (self::short($data, $entry, $big) !== 0x0112) continue;
            if (self::short($data, $entry + 2, $big) !== 3 ||
                self::long($data, $entry + 4, $big) !== 1) return null;
            return $entry + 8;
        }
        return null;
    }

    private static function short($data, $offset, $big): ?int
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) return null;
        return unpack($big ? 'nvalue' : 'vvalue', substr($data, $offset, 2))['value'];
    }

    private static function long($data, $offset, $big): ?int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) return null;
        return unpack($big ? 'Nvalue' : 'Vvalue', substr($data, $offset, 4))['value'];
    }
}
