<?php

$basePath = dirname(__DIR__);
$artisan = $basePath.DIRECTORY_SEPARATOR.'artisan';

if (function_exists('pcntl_fork')) {
    passthru(PHP_BINARY.' '.escapeshellarg($artisan).' pail --timeout=0', $exitCode);

    exit((int) $exitCode);
}

$logPath = $basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log';

fwrite(STDOUT, "Pail indisponible sans l'extension pcntl. Suivi de storage/logs/laravel.log...\n");

if (! is_dir(dirname($logPath))) {
    mkdir(dirname($logPath), 0777, true);
}

if (! is_file($logPath)) {
    touch($logPath);
}

$position = filesize($logPath) ?: 0;

while (true) {
    clearstatcache(false, $logPath);

    $size = filesize($logPath);
    if ($size === false) {
        sleep(1);

        continue;
    }

    if ($size < $position) {
        $position = 0;
    }

    if ($size > $position) {
        $handle = fopen($logPath, 'rb');
        if ($handle !== false) {
            fseek($handle, $position);

            while (! feof($handle)) {
                fwrite(STDOUT, (string) fread($handle, 8192));
            }

            $position = ftell($handle) ?: $size;
            fclose($handle);
        }
    }

    sleep(1);
}
