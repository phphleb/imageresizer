<?php
require dirname(__DIR__) . '/SimpleImage.php';
$legacy = [
'load'=>1,'save'=>2,'output'=>0,'getImage'=>0,'getWidth'=>0,'getHeight'=>0,
'getImageType'=>0,'getImageFormat'=>0,'getFilePath'=>0,'resizeToHeight'=>1,
'resizeToWidth'=>1,'scale'=>1,'resize'=>2,'resizeInCenter'=>2,
'cropBySelectedRegion'=>4,'addRgbColor'=>3,'resizeAllInCenter'=>2
];
$ref = new ReflectionClass('Phphleb\\Imageresizer\\SimpleImage');
foreach ($legacy as $name => $minArgs) {
  if (!$ref->hasMethod($name) || !$ref->getMethod($name)->isPublic() || $ref->getMethod($name)->getNumberOfRequiredParameters() !== $minArgs) {
    echo "FAIL legacy public: $name\n"; exit(1);
  }
}
echo 'PASS legacy public API names and mandatory parameter counts: ' . count($legacy) . "\n";
foreach (['createFromFile','createCanvas','keepAlpha','imageCopyResampled'] as $name) {
  if (!$ref->hasMethod($name) || !$ref->getMethod($name)->isProtected()) { echo 'FAIL legacy protected: '.$name . "\n"; exit(1); }
  echo 'PASS legacy protected: '.$name . "\n";
}
echo 'PASS processor default: '. $ref->getDefaultProperties()['version'] . "\n";
if ($ref->hasMethod('setStripMetadata')) { echo "FAIL: removed setStripMetadata must not exist in v3\n"; exit(1); }
if ($ref->getMethod('load')->getNumberOfParameters() !== 2 ||
    $ref->getMethod('load')->getParameters()[1]->isOptional() !== true ||
    $ref->getMethod('load')->getParameters()[1]->getDefaultValue() !== true) {
    echo "FAIL: load(filename, stripMetadata=true) signature\n"; exit(1);
}
echo "PASS: new load signature and no separate metadata configuration method\n";
