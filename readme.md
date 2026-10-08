# IMAGERESIZER

Библиотека предназначена для настраиваемого изменения размеров изображения. Улучшение стандартного класса.

### Инициализация
```php
use Phphleb\Imageresizer\SimpleImage;

$image = new SimpleImage();

// Путь к исходному файлу в формате JPEG, GIF, WEBP, BMP, WBMP или PNG
$image->load("/path/to/picture.jpg");
```
### Получение данных
```php
// Формат исходного изображения "jpeg", "gif", "webp", "bmp", "wbmp" или "png"
var_dump($image->getImageFormat());
```
```php
// Ширина в пикселах
var_dump($image->getWidth());
```
```php
// Высота в пикселах
var_dump($image->getHeight());
```
```php
// Путь до исходного файла
var_dump($image->getFilePath());
```
```php
// Получение данных изображения для включения в другое
$image->getImage();
```
### Изменение размеров
```php
// В пикселах по ширине
$image->resizeToWidth(650);
```
```php
// В пикселах по высоте
$image->resizeToHeight(650);
```
```php
// По ширине и высоте непропорционально
$image->resize(400, 200);
```
```php
// В процентах
$image->scale(50);
```
```php
// По центру с обрезанием по меньшей стороне
$image->resizeInCenter(100, 200);
```
```php
// По центру без обрезания (прозрачный фон для PNG)
$image->resizeAllInCenter(128, 200);
```
```php
// По центру без обрезания (задается цвет фона для всех типов изображений)
$image->resizeAllInCenter(128, 200, "#ffc025");
```
```php
// По центру без обрезания (задается цвет фона в формате RGB)
$image->resizeAllInCenter(128, 200, $image->addRgbColor(115, 70, 188));
```
### Кадрирование области
```php
// Кадрирование без изменения масштаба (ширина и высота, отступ слева, отступ сверху)
$image->cropBySelectedRegion(500, 300, 10, 15);
```

### Вывод изображения в браузер
```php
header("Content-type: image/jpeg");
$image->output();
```
или

```php
header("Content-type: image/png");
$image->output("png");
```
### Сохранение в файл
```php
// В тот же файл
$image->save("/path/to/picture.jpg", "jpeg");
```
```php
// С другим расширением и названием
$image->save("/path/to/picture2.png", "png");
```
```php
// Изменение исходного jpeg-изображения с указанием сжатия для JPEG
$image->save($image->getFilePath(), "jpeg", 80);
```
### Проверка
Проверки не подавляют стандартный вывод ошибок PHP
```php
//  Инициализация загруженного изображения
if ($image->load("/path/to/picture.jpg")){
	// success
} else {
	// error
}
```
```php
// При выводе в браузер
if ($image->output("gif")){
	// success
} else {
	// error
}
```
```php
// При сохранении изображения
if ($image->save("/path/to/picture.jpg", "jpeg")){
	// success
} else {
	// error
}
```

### Выбор обработчика
По умолчанию выбирается GD. Если GD недоступен или не смог открыть конкретный файл, в автоматическом режиме будет предпринята попытка через Imagick. Если доступен только Imagick, он используется автоматически. При отсутствии обоих расширений load() вернёт false, а причина будет доступна через getError().
```php
// Выбор обработчика перед загрузкой файла
$image->setProcessorVersion(SimpleImage::PROCESSOR_IMAGICK);

// Или принудительно GD
$image->setProcessorVersion(SimpleImage::PROCESSOR_GD);

// Автоматический выбор
$image->setProcessorVersion(SimpleImage::PROCESSOR_AUTO);

// Используемый обработчик
var_dump($image->getProcessorVersion());
```
Если выбранное расширение недоступно, setProcessorVersion() возвращает false.

### ICC-профили
При работе через GD запись и преобразование ICC недоступны.
```php
// Добавить профиль, если его нет
$image->setProfile(SimpleImage::PROFILE_SRGB);

// Задать профиль из собственного ICC-файла
$image->setProfile("/path/to/profile.icc");

// Принудительно заменить существующий профиль
$image->setProfile("/path/to/profile.icc", true);

// Преобразовать цвета в sRGB после загрузки файла
$image->convertToProfile(SimpleImage::PROFILE_SRGB);
```
setProfile() только назначает профиль, не изменяя пиксели. convertToProfile() преобразует цвета из исходного ICC в целевой. Если исходного ICC нет, сначала явно назначьте правильный профиль через setProfile(). Для RGB подходят RGB-профили (sRGB, Display P3), для CMYK — CMYK-профили; ошибочное назначение меняет цвета.

### Цветовое пространство и профиль
```php
// Семейство цветового пространства пикселей: RGB, CMYK, GRAY и др. (или null до загрузки)
var_dump($image->getImageColorspace());

// Имя встроенного ICC-профиля или null при его отсутствии
var_dump($image->getProfileName());
```
Определение RGB не означает наличие sRGB-профиля. Для правильного назначения ICC нужно знать исходное цветовое пространство.

### Метаданные

```php
// Обычная загрузка: удалить лишние метаданные при сохранении
$image->load("/path/to/picture.jpg");

// Сохранить исходные метаданные (Imagick)
$image->load("/path/to/picture.jpg", false);
```

### Ошибки
При неудаче load(), save(), output() и других методов возвращается false. Методы изменения размеров ничего не возвращают; ошибка доступна через getError().
```php
if (!$image->load("/path/to/picture.jpg")) {
    echo $image->getError()->getMessage();
}
```
При отсутствии ошибки getError() возвращает null.

-----------------------------------

 ![PHP](https://img.shields.io/badge/PHP->=7.2.0-blue)
