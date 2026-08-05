<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const VORON_API_BASE_URL = 'https://api.everent.me';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function dailyDatabase(): PDO
{
    $host = getenv('VORON_DB_HOST') ?: '127.0.0.1';
    $port = getenv('VORON_DB_PORT') ?: '3306';
    $name = getenv('VORON_DB_NAME') ?: 'carsharing_app';
    $user = getenv('VORON_DB_USER') ?: 'carsharing_app';
    $pass = getenv('VORON_DB_PASS');

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass === false ? '' : $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function apiRequest(string $path, ?array $form = null): string
{
    $curl = curl_init();
    $options = [
        CURLOPT_URL => VORON_API_BASE_URL . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'CarsharingDailyCatalogUpdate/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ];

    if ($form !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER] = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ];
    }

    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Сетевой запрос не выполнен: ' . ($curlError ?: 'неизвестная ошибка'));
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException("API вернул HTTP {$httpStatus} для {$path}.");
    }
    if (strlen($response) > 10 * 1024 * 1024) {
        throw new RuntimeException("Ответ API для {$path} превышает лимит 10 МБ.");
    }

    return $response;
}

function isDailyVehicleCandidate(array $item): bool
{
    if (!isset($item['id']) || !is_numeric($item['id'])) {
        return false;
    }

    foreach (['status', 'stateInfo', 'stateTime', 'serviceMode', 'isAllocated', 'fuel', 'latitude', 'longitude'] as $key) {
        if (array_key_exists($key, $item)) {
            return true;
        }
    }

    return false;
}

function collectDailyVehicles($node, array &$vehicles, int $depth = 0): void
{
    if (!is_array($node) || $depth > 10 || count($vehicles) >= 5000) {
        return;
    }
    if (isDailyVehicleCandidate($node)) {
        $vehicles[(string) $node['id']] = $node;
        return;
    }

    foreach ($node as $value) {
        collectDailyVehicles($value, $vehicles, $depth + 1);
    }
}

function decodeVehicles(string $json, string $source): array
{
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException("API вернул неожиданный JSON для {$source}.");
    }

    $vehicles = [];
    collectDailyVehicles($decoded, $vehicles);

    return $vehicles;
}

function mergeDailyVehicle(array $freeVehicle, array $busyVehicle): array
{
    $merged = $busyVehicle;
    foreach (['title', 'categoryTitle', 'baseCategoryTitle', 'typeId', 'fuel', 'latitude', 'longitude'] as $field) {
        if (!array_key_exists($field, $merged) || $merged[$field] === null || $merged[$field] === '') {
            $merged[$field] = $freeVehicle[$field] ?? null;
        }
    }

    return $merged;
}

function dailyStatusSlug(array $vehicle, string $endpoint): string
{
    $stateInfo = trim((string) ($vehicle['stateInfo'] ?? ''));
    if ($endpoint === 'free') {
        return 'free';
    }
    if (preg_match('/^Оплачено до\b/u', $stateInfo) === 1) {
        return 'rented_fixed';
    }
    if ($stateInfo === 'Поминутный тариф') {
        return 'rented_minute';
    }
    if ($stateInfo === 'На обслуживании') {
        return 'maintenance';
    }
    if ($stateInfo === 'У владельца') {
        return 'owner';
    }
    if ($stateInfo === '' && strpos($endpoint, 'busy') !== false) {
        return 'busy_unknown';
    }

    $codes = [0 => 'free', 1 => 'occupied', 4 => 'unavailable', 12 => 'reserved'];
    $statusCode = $vehicle['status'] ?? null;

    return $statusCode !== null && isset($codes[(int) $statusCode]) ? $codes[(int) $statusCode] : 'unknown';
}

function dailyNullableInt($value)
{
    return $value !== null && $value !== '' && is_numeric($value) ? (int) $value : null;
}

function dailyNullableFloat($value)
{
    return $value !== null && $value !== '' && is_numeric($value) ? (float) $value : null;
}

function dailyNullableBool($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    return $value ? 1 : 0;
}

$latitude = (float) (getenv('VORON_DEFAULT_LATITUDE') ?: '55.7558');
$longitude = (float) (getenv('VORON_DEFAULT_LONGITUDE') ?: '37.6173');
$catalogPathTemplate = getenv('VORON_CATALOG_PATH') ?: '/v6/catalog.php/getCatalog/{random}';
$catalogApiPath = str_replace('{random}', (string) random_int(100000, 999999), $catalogPathTemplate);
$form = [
    'latitude' => number_format($latitude, 7, '.', ''),
    'longitude' => number_format($longitude, 7, '.', ''),
];

$pdo = null;
$lockPdo = null;
$lockAcquired = false;

try {
    $lockPdo = dailyDatabase();
    $lockAcquired = (int) $lockPdo->query("SELECT GET_LOCK('carsharing_daily_catalog_update', 0)")->fetchColumn() === 1;
    if (!$lockAcquired) {
        throw new RuntimeException('Другой ежедневный запуск ещё не завершён.');
    }

    // Сначала получаем и проверяем все ответы. До этого момента база не изменяется.
    $catalogJson = apiRequest($catalogApiPath);
    $catalog = json_decode($catalogJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($catalog) || ($catalog['success'] ?? false) !== true || empty($catalog['result']['categories'])) {
        throw new RuntimeException('API вернул неполный каталог транспорта.');
    }

    $freeVehicles = decodeVehicles(
        apiRequest('/v9/map.php/free_list_for_guest', $form),
        'free_list_for_guest'
    );
    $busyVehicles = decodeVehicles(
        apiRequest('/v9/map.php/busy_list_for_guest', $form),
        'busy_list_for_guest'
    );

    $observations = [];
    foreach ($freeVehicles as $externalId => $vehicle) {
        $observations[$externalId] = ['vehicle' => $vehicle, 'endpoint' => 'free'];
    }
    foreach ($busyVehicles as $externalId => $vehicle) {
        if (isset($observations[$externalId])) {
            $observations[$externalId] = [
                'vehicle' => mergeDailyVehicle($observations[$externalId]['vehicle'], $vehicle),
                'endpoint' => 'free+busy',
            ];
        } else {
            $observations[$externalId] = ['vehicle' => $vehicle, 'endpoint' => 'busy'];
        }
    }

    $openServerRoot = dirname(__DIR__, 2);
    $catalogFile = $openServerRoot . '/userdata/temp/carsharing_catalog_latest.json';
    if (file_put_contents($catalogFile, $catalogJson, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить полученный каталог во временный файл.');
    }

    // Используем существующий проверенный импортёр каталога.
    $argv = [__DIR__ . '/import_catalog.php', $catalogFile];
    require __DIR__ . '/import_catalog.php';

    $statusRows = $pdo->query('SELECT id, slug FROM statuses WHERE is_active = 1')->fetchAll();
    $statusIds = [];
    foreach ($statusRows as $statusRow) {
        $statusIds[(string) $statusRow['slug']] = (int) $statusRow['id'];
    }
    if (!isset($statusIds['unknown'])) {
        throw new RuntimeException('В справочнике отсутствует статус unknown.');
    }

    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
    $observedAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
    $observedAtSql = $observedAt->format('Y-m-d H:i:s');
    $newUntilSql = $observedAt->modify('+3 days')->format('Y-m-d H:i:s');

    $upsertVehicle = $pdo->prepare(
        'INSERT INTO vehicles
            (external_id, title, category_title, base_category_title, type_external_id,
             fuel_level, latitude, longitude, current_status_id, source_status_code,
             state_info, status_source_endpoint, in_garage, service_mode, is_allocated,
             first_seen_at, last_seen_at, new_until, is_active)
         VALUES
            (:external_id, :title, :category_title, :base_category_title, :type_external_id,
             :fuel_level, :latitude, :longitude, :current_status_id, :source_status_code,
             :state_info, :status_source_endpoint, :in_garage, :service_mode, :is_allocated,
             :first_seen_at, :last_seen_at, :new_until, 1)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            title = COALESCE(VALUES(title), title),
            category_title = COALESCE(VALUES(category_title), category_title),
            base_category_title = COALESCE(VALUES(base_category_title), base_category_title),
            type_external_id = COALESCE(VALUES(type_external_id), type_external_id),
            fuel_level = COALESCE(VALUES(fuel_level), fuel_level),
            latitude = COALESCE(VALUES(latitude), latitude),
            longitude = COALESCE(VALUES(longitude), longitude),
            last_seen_at = VALUES(last_seen_at),
            is_active = 1'
    );

    $pdo->beginTransaction();
    foreach ($observations as $externalId => $observation) {
        $vehicle = $observation['vehicle'];
        $slug = dailyStatusSlug($vehicle, $observation['endpoint']);
        $upsertVehicle->execute([
            'external_id' => (int) $externalId,
            'title' => isset($vehicle['title']) ? (string) $vehicle['title'] : null,
            'category_title' => isset($vehicle['categoryTitle']) ? (string) $vehicle['categoryTitle'] : null,
            'base_category_title' => isset($vehicle['baseCategoryTitle']) ? (string) $vehicle['baseCategoryTitle'] : null,
            'type_external_id' => dailyNullableInt($vehicle['typeId'] ?? null),
            'fuel_level' => dailyNullableInt($vehicle['fuel'] ?? null),
            'latitude' => dailyNullableFloat($vehicle['latitude'] ?? null),
            'longitude' => dailyNullableFloat($vehicle['longitude'] ?? null),
            'current_status_id' => $statusIds[$slug] ?? $statusIds['unknown'],
            'source_status_code' => dailyNullableInt($vehicle['status'] ?? null),
            'state_info' => isset($vehicle['stateInfo']) ? trim((string) $vehicle['stateInfo']) : null,
            'status_source_endpoint' => $observation['endpoint'],
            'in_garage' => dailyNullableBool($vehicle['inGarage'] ?? null),
            'service_mode' => dailyNullableInt($vehicle['serviceMode'] ?? null),
            'is_allocated' => dailyNullableBool($vehicle['isAllocated'] ?? null),
            'first_seen_at' => $observedAtSql,
            'last_seen_at' => $observedAtSql,
            'new_until' => $newUntilSql,
        ]);
    }
    $pdo->commit();

    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
    echo 'Обновление завершено: ' . $observedAt->format('Y-m-d H:i:s') . "\n";
    echo 'Получено автомобилей: ' . count($observations) . "\n";
    echo 'Новых автомобилей: ' . max(0, $afterCount - $beforeCount) . "\n";
    echo "Статусы и события аренды не записывались.\n";
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Ошибка ежедневного обновления: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($lockPdo instanceof PDO && $lockAcquired) {
        try {
            $lockPdo->query("SELECT RELEASE_LOCK('carsharing_daily_catalog_update')");
        } catch (Throwable $ignored) {
        }
    }
}
