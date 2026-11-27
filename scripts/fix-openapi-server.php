<?php

$path = __DIR__.'/../public/openapi.json';

$spec = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$spec['servers'] = [
    ['url' => '/', 'description' => 'Current Environment'],
];

file_put_contents(
    $path,
    json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL
);

echo "OpenAPI server set to relative /.\n";
