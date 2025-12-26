<?php

$envFile =  '.env';
if (!file_exists($envFile)) {
    if (file_exists('.env.example')) {
        copy('.env.example', $envFile);
    } else {
        throw new Exception("Arquivo .env não encontrado e .env.example também não existe.");
    }
}
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}
$interface = json_decode(file_get_contents(__DIR__ . '/configInterface.json'), true);
$paths = $interface['autoload'];
$allowObservable = $interface['reloadCaseFileModify'];
$nameFiles = [];
$cachePages = [];
foreach ($paths as $path) {
    $directory = new DirectoryIterator(__DIR__ . "/$path");
    foreach ($directory as $fileInfo) {
        $nameFile = $fileInfo->getFilename();
        if (strlen($nameFile) > 2) {
            $nameFiles[] = (strlen($path) > 1) ? __DIR__ . '/' . $path . "/" . $nameFile : $nameFile;
        }
    }
}
foreach ($nameFiles as $key => $file) if (!str_contains($file, 'vendor')) include $file;