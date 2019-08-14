Библиотека предназначена для настраиваемого изменения размеров изображения. Улучшение стандартного класса.

### Инициализация
```php
use Phphleb\Imageresizer\SimpleImage;

$image = new SimpleImage();

// Путь к файлу в формате JPEG, GIF или PNG
$image->load("test.jpg");
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
// По размерам непропорционально
$image->resize(400, 200);
```
```php
// По центру
$image->resizeInCenter(100, 200);
```
```php
// В процентах
$image->scale(50);
```
```php
// Без обрезания (прозрачный фон для PNG)
$image->resizeAllInCenter(128, 200);
```
```php
// Без обрезания (задается цвет фона для всех типов изображений)
$image->resizeAllInCenter(128, 200, "#ffc025");
```
```php
// Без обрезания (задается цвет фона в формате RGB)
$image->resizeAllInCenter(128, 200, $image->addingRGBColor(115, 70, 188));
```

### Кадрирование области
```php
// Кадрирование без изменения масштаба (ширина и высота, отступ слева, отступ сверху)
$image->cropBySelectedRegion(500, 300, 10, 15);
```

### Получение данных
```php
// Формат исходного изображения "jpeg", "gif" или "png"
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
var_dump($image->getFileName());
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
$image->save();
```
```php
// С другим расширением и названием (без указания формата вторым параметром - сохранение с исходным)
$image->save("test2.png", "png");
```
```php
// С указанием сжатия для JPEG
$image->save($image->getFileName(), "jpeg", 80);
```
```php
// Изменение сжатия исходного jpeg-изображения с указанием прав доступа к файлу
$image->save($image->getFileName(), $image->getImageType(), 75, 777);
```
