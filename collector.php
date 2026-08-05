<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    session_start();
}

if ($isCli && in_array('--help', $argv, true)) {
    echo "Сбор статусов автомобилей Voron.\n";
    echo "Запуск: php collector.php\n";
    echo "Используются координаты по умолчанию: 55.7558, 37.6173.\n";
    exit(0);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isVehicleCandidate(array $item): bool
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

function collectVehicleCandidates($node, array &$vehicles, int $depth = 0)
{
    if (!is_array($node) || $depth > 10 || count($vehicles) >= 5000) {
        return;
    }

    if (isVehicleCandidate($node)) {
        $vehicles[(string) $node['id']] = $node;
        return;
    }

    foreach ($node as $value) {
        collectVehicleCandidates($value, $vehicles, $depth + 1);
    }
}

function requestVehicles(string $path, float $latitude, float $longitude): array
{
    $body = http_build_query([
        'latitude' => number_format($latitude, 7, '.', ''),
        'longitude' => number_format($longitude, 7, '.', ''),
    ], '', '&', PHP_QUERY_RFC3986);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.everent.me' . $path,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_USERAGENT => 'VoronLocalCollector/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);

    $response = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Сетевой запрос не выполнен: ' . ($curlError !== '' ? $curlError : 'неизвестная ошибка'));
    }
    if (strlen($response) > 5 * 1024 * 1024) {
        throw new RuntimeException('Ответ API превышает безопасный лимит 5 МБ.');
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException("API вернул HTTP {$httpStatus} для {$path}.");
    }

    $decoded = json_decode($response, true, 512);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new RuntimeException("API вернул некорректный JSON для {$path}.");
    }

    $vehicles = [];
    collectVehicleCandidates($decoded, $vehicles);

    return $vehicles;
}

function classifyStatus(array $vehicle, string $endpoint): string
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

    $statusCode = $vehicle['status'] ?? null;
    $codes = [0 => 'free', 1 => 'occupied', 4 => 'unavailable', 12 => 'reserved'];
    if ($statusCode !== null && $statusCode !== '' && isset($codes[(int) $statusCode])) {
        return $codes[(int) $statusCode];
    }

    return 'unknown';
}

function transitionEventType(string $fromSlug, string $toSlug): string
{
    $rentalStatuses = ['rented_fixed', 'rented_minute', 'occupied', 'reserved'];
    $fromIsRental = in_array($fromSlug, $rentalStatuses, true);
    $toIsRental = in_array($toSlug, $rentalStatuses, true);

    if (!$fromIsRental && $toIsRental) {
        return 'rental_started';
    }
    if ($toSlug === 'free' && $fromSlug !== 'free') {
        return 'returned_free';
    }
    if ($toSlug === 'maintenance') {
        return 'maintenance_started';
    }
    if ($toSlug === 'owner') {
        return 'owner_started';
    }
    if ($fromSlug === 'free' && $toSlug !== 'free') {
        return 'left_free';
    }

    return 'status_changed';
}

function nullableInt($value)
{
    return $value !== null && $value !== '' && is_numeric($value) ? (int) $value : null;
}

function nullableFloat($value)
{
    return $value !== null && $value !== '' && is_numeric($value) ? (float) $value : null;
}

function nullableBool($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    return $value ? 1 : 0;
}

function mergeVehicleData(array $freeVehicle, array $busyVehicle): array
{
    $merged = $busyVehicle;
    foreach (['title', 'categoryTitle', 'baseCategoryTitle', 'typeId', 'fuel', 'latitude', 'longitude'] as $field) {
        if (!array_key_exists($field, $merged) || $merged[$field] === null || $merged[$field] === '') {
            $merged[$field] = $freeVehicle[$field] ?? null;
        }
    }

    return $merged;
}

function database(): PDO
{
    $host = getenv('VORON_DB_HOST') ?: '127.0.0.1';
    $port = getenv('VORON_DB_PORT') ?: '3306';
    $name = getenv('VORON_DB_NAME') ?: 'carsharing_app';
    $user = getenv('VORON_DB_USER') ?: 'carsharing_app';
    $pass = getenv('VORON_DB_PASS');
    $pass = $pass === false ? '' : $pass;

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

if (!$isCli && !isset($_SESSION['collector_token'])) {
    $_SESSION['collector_token'] = bin2hex(random_bytes(24));
}

$latitudeInput = isset($_POST['latitude']) ? trim((string) $_POST['latitude']) : '55.7558';
$longitudeInput = isset($_POST['longitude']) ? trim((string) $_POST['longitude']) : '37.6173';
$message = null;
$errorMessage = null;
$result = null;
$recentRuns = [];

if ($isCli || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = isset($_POST['token']) ? (string) $_POST['token'] : '';
    $latitude = filter_var($latitudeInput, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($longitudeInput, FILTER_VALIDATE_FLOAT);

    if (!$isCli && !hash_equals((string) $_SESSION['collector_token'], $token)) {
        $errorMessage = 'Защитный токен устарел. Обновите страницу и повторите действие.';
    } elseif ($latitude === false || $latitude < -90 || $latitude > 90) {
        $errorMessage = 'Широта должна быть числом от -90 до 90.';
    } elseif ($longitude === false || $longitude < -180 || $longitude > 180) {
        $errorMessage = 'Долгота должна быть числом от -180 до 180.';
    } else {
        $pdo = null;
        $runId = null;
        try {
            $pdo = database();
            $createRun = $pdo->prepare(
                'INSERT INTO collector_runs
                    (endpoint, started_at, run_status, latitude, longitude)
                 VALUES (:endpoint, :started_at, :run_status, :latitude, :longitude)'
            );
            $startedAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
            $createRun->execute([
                'endpoint' => 'free_list_for_guest + busy_list_for_guest',
                'started_at' => $startedAt->format('Y-m-d H:i:s'),
                'run_status' => 'running',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
            $runId = (int) $pdo->lastInsertId();

            $freeVehicles = requestVehicles('/v9/map.php/free_list_for_guest', (float) $latitude, (float) $longitude);
            $busyVehicles = requestVehicles('/v9/map.php/busy_list_for_guest', (float) $latitude, (float) $longitude);

            $observations = [];
            foreach ($freeVehicles as $externalId => $vehicle) {
                $observations[$externalId] = [
                    'vehicle' => $vehicle,
                    'endpoint' => 'free',
                ];
            }

            $overlap = 0;
            foreach ($busyVehicles as $externalId => $vehicle) {
                if (isset($observations[$externalId])) {
                    $observations[$externalId] = [
                        'vehicle' => mergeVehicleData($observations[$externalId]['vehicle'], $vehicle),
                        'endpoint' => 'free+busy',
                    ];
                    $overlap++;
                } else {
                    $observations[$externalId] = [
                        'vehicle' => $vehicle,
                        'endpoint' => 'busy',
                    ];
                }
            }

            $statusRows = $pdo->query('SELECT id, slug FROM statuses WHERE is_active = 1')->fetchAll();
            $statusIds = [];
            $statusSlugsById = [];
            foreach ($statusRows as $statusRow) {
                $statusIds[$statusRow['slug']] = (int) $statusRow['id'];
                $statusSlugsById[(int) $statusRow['id']] = (string) $statusRow['slug'];
            }

            $requiredStatuses = ['free', 'rented_fixed', 'rented_minute', 'maintenance', 'owner', 'busy_unknown', 'unknown'];
            foreach ($requiredStatuses as $requiredStatus) {
                if (!isset($statusIds[$requiredStatus])) {
                    throw new RuntimeException("В справочнике отсутствует статус {$requiredStatus}.");
                }
            }

            $observedAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
            $pdo->beginTransaction();

            $selectPrevious = $pdo->prepare(
                'SELECT id, current_status_id
                 FROM vehicles
                 WHERE external_id = :external_id
                 FOR UPDATE'
            );

            $upsertVehicle = $pdo->prepare(
                'INSERT INTO vehicles
                    (external_id, title, category_title, base_category_title, type_external_id,
                     fuel_level, latitude, longitude, current_status_id, source_status_code,
                     state_info, state_time_seconds, state_started_at, status_source_endpoint,
                     in_garage, service_mode, is_allocated, first_seen_at, last_seen_at, new_until, is_active)
                 VALUES
                    (:external_id, :title, :category_title, :base_category_title, :type_external_id,
                     :fuel_level, :latitude, :longitude, :current_status_id, :source_status_code,
                     :state_info, :state_time_seconds, :state_started_at, :status_source_endpoint,
                     :in_garage, :service_mode, :is_allocated, :first_seen_at, :last_seen_at, :new_until, 1)
                 ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    title = COALESCE(VALUES(title), title),
                    category_title = COALESCE(VALUES(category_title), category_title),
                    base_category_title = COALESCE(VALUES(base_category_title), base_category_title),
                    type_external_id = COALESCE(VALUES(type_external_id), type_external_id),
                    fuel_level = COALESCE(VALUES(fuel_level), fuel_level),
                    latitude = COALESCE(VALUES(latitude), latitude),
                    longitude = COALESCE(VALUES(longitude), longitude),
                    current_status_id = VALUES(current_status_id),
                    source_status_code = VALUES(source_status_code),
                    state_info = VALUES(state_info),
                    state_time_seconds = VALUES(state_time_seconds),
                    state_started_at = VALUES(state_started_at),
                    status_source_endpoint = VALUES(status_source_endpoint),
                    in_garage = VALUES(in_garage),
                    service_mode = VALUES(service_mode),
                    is_allocated = VALUES(is_allocated),
                    last_seen_at = VALUES(last_seen_at),
                    is_active = 1'
            );

            $insertHistory = $pdo->prepare(
                'INSERT INTO vehicle_status_history
                    (vehicle_id, collector_run_id, status_id, source_status_code,
                     state_info, state_time_seconds, state_started_at, source_endpoint,
                     in_garage, service_mode, is_allocated, latitude, longitude,
                     observed_at, raw_payload_hash)
                 VALUES
                    (:vehicle_id, :collector_run_id, :status_id, :source_status_code,
                     :state_info, :state_time_seconds, :state_started_at, :source_endpoint,
                     :in_garage, :service_mode, :is_allocated, :latitude, :longitude,
                     :observed_at, :raw_payload_hash)'
            );

            $insertEvent = $pdo->prepare(
                'INSERT INTO vehicle_status_events
                    (vehicle_id, collector_run_id, from_status_id, to_status_id,
                     event_type, detected_at, state_started_at)
                 VALUES
                    (:vehicle_id, :collector_run_id, :from_status_id, :to_status_id,
                     :event_type, :detected_at, :state_started_at)'
            );

            $counts = [];
            foreach ($observations as $externalId => $observation) {
                $vehicle = $observation['vehicle'];
                $endpoint = $observation['endpoint'];
                $slug = classifyStatus($vehicle, $endpoint);
                $statusId = $statusIds[$slug] ?? $statusIds['unknown'];
                $stateTime = nullableInt($vehicle['stateTime'] ?? null);
                $stateStartedAt = null;
                if ($stateTime !== null && $stateTime >= 0) {
                    $stateStartedAt = $observedAt->modify('-' . $stateTime . ' seconds')->format('Y-m-d H:i:s');
                }

                $selectPrevious->execute(['external_id' => (int) $externalId]);
                $previousVehicle = $selectPrevious->fetch();

                $values = [
                    'external_id' => (int) $externalId,
                    'title' => isset($vehicle['title']) ? (string) $vehicle['title'] : null,
                    'category_title' => isset($vehicle['categoryTitle']) ? (string) $vehicle['categoryTitle'] : null,
                    'base_category_title' => isset($vehicle['baseCategoryTitle']) ? (string) $vehicle['baseCategoryTitle'] : null,
                    'type_external_id' => nullableInt($vehicle['typeId'] ?? null),
                    'fuel_level' => nullableInt($vehicle['fuel'] ?? null),
                    'latitude' => nullableFloat($vehicle['latitude'] ?? null),
                    'longitude' => nullableFloat($vehicle['longitude'] ?? null),
                    'current_status_id' => $statusId,
                    'source_status_code' => nullableInt($vehicle['status'] ?? null),
                    'state_info' => isset($vehicle['stateInfo']) ? trim((string) $vehicle['stateInfo']) : null,
                    'state_time_seconds' => $stateTime,
                    'state_started_at' => $stateStartedAt,
                    'status_source_endpoint' => $endpoint,
                    'in_garage' => nullableBool($vehicle['inGarage'] ?? null),
                    'service_mode' => nullableInt($vehicle['serviceMode'] ?? null),
                    'is_allocated' => nullableBool($vehicle['isAllocated'] ?? null),
                    'first_seen_at' => $observedAt->format('Y-m-d H:i:s'),
                    'last_seen_at' => $observedAt->format('Y-m-d H:i:s'),
                    'new_until' => $observedAt->modify('+3 days')->format('Y-m-d H:i:s'),
                ];
                $upsertVehicle->execute($values);
                $vehicleId = (int) $pdo->lastInsertId();

                $insertHistory->execute([
                    'vehicle_id' => $vehicleId,
                    'collector_run_id' => $runId,
                    'status_id' => $statusId,
                    'source_status_code' => $values['source_status_code'],
                    'state_info' => $values['state_info'],
                    'state_time_seconds' => $stateTime,
                    'state_started_at' => $stateStartedAt,
                    'source_endpoint' => $endpoint,
                    'in_garage' => $values['in_garage'],
                    'service_mode' => $values['service_mode'],
                    'is_allocated' => $values['is_allocated'],
                    'latitude' => $values['latitude'],
                    'longitude' => $values['longitude'],
                    'observed_at' => $observedAt->format('Y-m-d H:i:s'),
                    'raw_payload_hash' => hash('sha256', json_encode($vehicle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
                ]);

                if ($previousVehicle !== false && $previousVehicle['current_status_id'] !== null) {
                    $previousStatusId = (int) $previousVehicle['current_status_id'];
                    if ($previousStatusId !== $statusId) {
                        $fromSlug = $statusSlugsById[$previousStatusId] ?? 'unknown';
                        $insertEvent->execute([
                            'vehicle_id' => $vehicleId,
                            'collector_run_id' => $runId,
                            'from_status_id' => $previousStatusId,
                            'to_status_id' => $statusId,
                            'event_type' => transitionEventType($fromSlug, $slug),
                            'detected_at' => $observedAt->format('Y-m-d H:i:s'),
                            'state_started_at' => $stateStartedAt,
                        ]);
                    }
                }

                if (!isset($counts[$slug])) {
                    $counts[$slug] = 0;
                }
                $counts[$slug]++;
            }

            $finishRun = $pdo->prepare(
                'UPDATE collector_runs
                 SET finished_at = :finished_at, run_status = :run_status, http_status = 200,
                     vehicles_received = :vehicles_received, free_received = :free_received,
                     busy_received = :busy_received, overlap_count = :overlap_count
                 WHERE id = :id'
            );
            $finishRun->execute([
                'finished_at' => $observedAt->format('Y-m-d H:i:s'),
                'run_status' => 'success',
                'vehicles_received' => count($observations),
                'free_received' => count($freeVehicles),
                'busy_received' => count($busyVehicles),
                'overlap_count' => $overlap,
                'id' => $runId,
            ]);

            $pdo->commit();
            $result = [
                'run_id' => $runId,
                'total' => count($observations),
                'free_received' => count($freeVehicles),
                'busy_received' => count($busyVehicles),
                'overlap' => $overlap,
                'counts' => $counts,
            ];
            $message = 'Снимок успешно записан в базу данных.';
            if (!$isCli) {
                $_SESSION['collector_token'] = bin2hex(random_bytes(24));
            }
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($pdo instanceof PDO && $runId !== null) {
                try {
                    $failedRun = $pdo->prepare(
                        'UPDATE collector_runs
                         SET finished_at = :finished_at, run_status = :run_status, error_message = :error_message
                         WHERE id = :id'
                    );
                    $failedRun->execute([
                        'finished_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s'),
                        'run_status' => 'failed',
                        'error_message' => mb_substr($exception->getMessage(), 0, 2000, 'UTF-8'),
                        'id' => $runId,
                    ]);
                } catch (Throwable $ignored) {
                }
            }
            error_log('Voron collector error: ' . $exception->getMessage());
            $errorMessage = 'Снимок не записан: ' . $exception->getMessage();
        }
    }
}

if ($isCli) {
    if ($errorMessage !== null) {
        fwrite(STDERR, $errorMessage . "\n");
        exit(1);
    }
    if ($result === null) {
        fwrite(STDERR, "Сборщик не вернул результат.\n");
        exit(1);
    }

    echo sprintf(
        "Снимок записан. Запуск №%d, автомобилей: %d, свободных: %d, занятых: %d, пересечений: %d.\n",
        (int) $result['run_id'],
        (int) $result['total'],
        (int) $result['free_received'],
        (int) $result['busy_received'],
        (int) $result['overlap']
    );
    exit(0);
}

try {
    $pdoForRuns = isset($pdo) && $pdo instanceof PDO ? $pdo : database();
    $recentRuns = $pdoForRuns->query(
        'SELECT id, started_at, run_status, vehicles_received, free_received, busy_received, overlap_count
         FROM collector_runs
         ORDER BY id DESC
         LIMIT 10'
    )->fetchAll();
} catch (Throwable $exception) {
    if ($errorMessage === null) {
        $errorMessage = 'Не удалось подключиться к базе данных.';
    }
}

$statusLabels = [
    'free' => 'Свободны',
    'rented_fixed' => 'Арендованы до времени',
    'rented_minute' => 'Поминутная аренда',
    'maintenance' => 'На обслуживании',
    'owner' => 'У владельца',
    'busy_unknown' => 'Заняты без причины',
    'unknown' => 'Не определены',
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сбор состояний Voron</title>
    <style>
        :root { --page:#f4f6f8; --surface:#fff; --text:#182026; --muted:#687680; --line:#dde4e8; --accent:#171b1f; --ok:#24714a; --ok-bg:#eaf7ef; --error:#a43636; --error-bg:#fff0f0; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:var(--page); color:var(--text); font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        .page { width:min(920px,calc(100% - 32px)); margin:0 auto; padding:48px 0 72px; }
        .back { color:var(--muted); text-decoration:none; font-size:14px; }
        header { margin:28px 0 24px; }
        h1 { margin:0; font-size:clamp(32px,5vw,48px); letter-spacing:-.04em; }
        .intro { max-width:720px; color:var(--muted); line-height:1.6; }
        .panel,.runs { border:1px solid var(--line); border-radius:16px; background:var(--surface); padding:20px; }
        form { display:grid; grid-template-columns:1fr 1fr auto; gap:12px; align-items:end; }
        label { display:block; margin-bottom:6px; color:var(--muted); font-size:12px; font-weight:700; }
        input { width:100%; border:1px solid var(--line); border-radius:10px; padding:11px 12px; font:inherit; }
        button { border:0; border-radius:10px; background:var(--accent); color:#fff; padding:12px 18px; font:inherit; font-weight:700; cursor:pointer; }
        .note { margin:14px 0 0; color:var(--muted); font-size:13px; line-height:1.5; }
        .message { margin-top:16px; padding:14px 16px; border-radius:12px; }
        .message.ok { border:1px solid #b9dec8; background:var(--ok-bg); color:var(--ok); }
        .message.error { border:1px solid #efbcbc; background:var(--error-bg); color:var(--error); }
        .summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-top:16px; }
        .card { border:1px solid var(--line); border-radius:12px; background:var(--surface); padding:14px 16px; }
        .card strong { display:block; font-size:24px; }
        .card span { color:var(--muted); font-size:12px; }
        .runs { margin-top:24px; overflow-x:auto; }
        h2 { margin:0 0 14px; font-size:20px; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th,td { padding:10px 12px; border-bottom:1px solid var(--line); text-align:left; white-space:nowrap; }
        th { color:var(--muted); font-size:11px; text-transform:uppercase; }
        tr:last-child td { border-bottom:0; }
        .success { color:var(--ok); } .failed { color:var(--error); }
        @media(max-width:700px){ .page{width:min(100% - 20px,920px);padding-top:28px} form{grid-template-columns:1fr} }
    </style>
</head>
<body>
<main class="page">
    <a class="back" href="index.php">← Вернуться в каталог</a>
    <header>
        <h1>Сбор состояний</h1>
        <p class="intro">Один ручной запуск делает два гостевых запроса: свободные и занятые автомобили. Затем снимок целиком записывается в базу. Персональные данные, токены и сведения об аккаунте не запрашиваются.</p>
    </header>

    <section class="panel">
        <form method="post">
            <input type="hidden" name="token" value="<?= e((string) $_SESSION['collector_token']) ?>">
            <div>
                <label for="latitude">Широта области поиска</label>
                <input id="latitude" name="latitude" required inputmode="decimal" value="<?= e($latitudeInput) ?>">
            </div>
            <div>
                <label for="longitude">Долгота области поиска</label>
                <input id="longitude" name="longitude" required inputmode="decimal" value="<?= e($longitudeInput) ?>">
            </div>
            <button type="submit">Собрать и записать снимок</button>
        </form>
        <p class="note">Если любой из двух запросов завершится ошибкой, данные автомобилей не будут записаны. Координаты сохраняются у запуска, чтобы разные области поиска не смешивались.</p>
    </section>

    <?php if ($errorMessage !== null): ?>
        <div class="message error" role="alert"><?= e($errorMessage) ?></div>
    <?php elseif ($message !== null): ?>
        <div class="message ok"><?= e($message) ?> Запуск №<?= (int) $result['run_id'] ?>.</div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <section class="summary" aria-label="Результат сбора">
            <div class="card"><strong><?= (int) $result['total'] ?></strong><span>уникальных автомобилей</span></div>
            <div class="card"><strong><?= (int) $result['free_received'] ?></strong><span>в выдаче свободных</span></div>
            <div class="card"><strong><?= (int) $result['busy_received'] ?></strong><span>в выдаче занятых</span></div>
            <?php foreach ($result['counts'] as $slug => $count): ?>
                <div class="card"><strong><?= (int) $count ?></strong><span><?= e($statusLabels[$slug] ?? $slug) ?></span></div>
            <?php endforeach; ?>
            <?php if ($result['overlap'] > 0): ?>
                <div class="card"><strong><?= (int) $result['overlap'] ?></strong><span>присутствуют в обеих выдачах</span></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="runs">
        <h2>Последние запуски</h2>
        <?php if ($recentRuns === []): ?>
            <p class="note">Сбор ещё не запускался.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>№</th><th>Время</th><th>Результат</th><th>Всего</th><th>Свободных</th><th>Занятых</th><th>Пересечение</th></tr></thead>
                <tbody>
                <?php foreach ($recentRuns as $run): ?>
                    <tr>
                        <td><?= (int) $run['id'] ?></td>
                        <td><?= e((string) $run['started_at']) ?></td>
                        <td class="<?= e((string) $run['run_status']) ?>"><?= e((string) $run['run_status']) ?></td>
                        <td><?= (int) $run['vehicles_received'] ?></td>
                        <td><?= (int) $run['free_received'] ?></td>
                        <td><?= (int) $run['busy_received'] ?></td>
                        <td><?= (int) $run['overlap_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
