<?php

declare(strict_types=1);

$databaseConfigPath = getenv('CARSHARING_APP_ENV_FILE');
if ($databaseConfigPath === false || $databaseConfigPath === '') {
    $databaseConfigPath = __DIR__ . '/.env';
}

if (is_readable($databaseConfigPath)) {
    $databaseConfig = parse_ini_file($databaseConfigPath, false, INI_SCANNER_RAW);
    if ($databaseConfig === false) {
        throw new RuntimeException('Не удалось прочитать локальную конфигурацию базы данных.');
    }

    foreach (['VORON_DB_HOST', 'VORON_DB_PORT', 'VORON_DB_NAME', 'VORON_DB_USER', 'VORON_DB_PASS'] as $key) {
        if (array_key_exists($key, $databaseConfig) && getenv($key) === false) {
            $value = (string) $databaseConfig[$key];
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
