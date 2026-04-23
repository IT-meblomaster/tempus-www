<?php
$file = __DIR__ . '/../cache/photos/test.txt';
var_dump($file);
var_dump(file_put_contents($file, "OK\n"));