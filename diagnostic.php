<?php

declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function statusLabel($status): string
{
    $labels = [
        0 => 'Свободен',
        1 => 'Занят',
        4 => 'Недоступен',
        12 => 'Зарезервирован',
    ];

    if ($status === null || $status === '') {
        return 'Не передан';
    }

    $code = (int) $status;
    return $labels[$code] ?? 'Неизвестный код';
}

function isVehicleCandidate(array $item): bool
{
    if (!array_key_exists('id', $item)) {
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
        $id = (string) $node['id'];
        $vehicles[$id] = $node;
        return;
    }

    foreach ($node as $value) {
        collectVehicleCandidates($value, $vehicles, $depth + 1);
    }
}

function safeVehicle(array $vehicle): array
{
    $allowedFields = [
        'id',
        'title',
        'categoryTitle',
        'baseCategoryTitle',
        'typeId',
        'status',
        'stateInfo',
        'stateTime',
        'serviceMode',
        'isAllocated',
        'inGarage',
        'fuel',
        'latitude',
        'longitude',
    ];

    $safe = [];
    foreach ($allowedFields as $field) {
        $safe[$field] = $vehicle[$field] ?? null;
    }

    return $safe;
}

function classifyVehicleState(array $vehicle, string $selectedEndpoint): array
{
    $stateInfo = trim((string) ($vehicle['stateInfo'] ?? ''));

    if ($stateInfo === 'На обслуживании') {
        return ['key' => 'maintenance', 'label' => 'На обслуживании'];
    }
    if ($stateInfo === 'У владельца') {
        return ['key' => 'owner', 'label' => 'У владельца'];
    }
    if (preg_match('/^Оплачено до\b/u', $stateInfo) === 1) {
        return ['key' => 'rented', 'label' => 'Арендован / оплачен'];
    }
    if ($stateInfo === 'Поминутный тариф') {
        return ['key' => 'rented', 'label' => 'Арендован / оплачен'];
    }

    $status = $vehicle['status'] ?? null;
    if ($status !== null && $status !== '') {
        $statusMap = [
            0 => ['key' => 'free', 'label' => 'Свободен'],
            1 => ['key' => 'occupied', 'label' => 'Занят'],
            4 => ['key' => 'unavailable', 'label' => 'Недоступен'],
            12 => ['key' => 'reserved', 'label' => 'Зарезервирован'],
        ];
        $statusCode = (int) $status;
        if (isset($statusMap[$statusCode])) {
            return $statusMap[$statusCode];
        }
    }

    if ($stateInfo !== '') {
        return ['key' => 'other', 'label' => 'Другое состояние'];
    }

    if ($selectedEndpoint === 'busy') {
        return ['key' => 'busy_unknown', 'label' => 'Занят, причина не указана'];
    }

    return ['key' => 'unknown', 'label' => 'Состояние не определено'];
}

function stateInfoVariant(array $vehicle): string
{
    $stateInfo = trim((string) ($vehicle['stateInfo'] ?? ''));
    if ($stateInfo === '') {
        return '(пусто)';
    }
    if (preg_match('/^Оплачено до\b/u', $stateInfo) === 1) {
        return 'Оплачено до …';
    }

    return $stateInfo;
}

$endpoints = [
    'list' => [
        'label' => 'Общий список',
        'path' => '/v9/map.php/list_for_guest',
        'hint' => 'Предпочтительный вариант для изучения поля status.',
    ],
    'free' => [
        'label' => 'Свободные автомобили',
        'path' => '/v9/map.php/free_list_for_guest',
        'hint' => 'Контрольная выдача автомобилей, которые API считает свободными.',
    ],
    'busy' => [
        'label' => 'Занятые автомобили',
        'path' => '/v9/map.php/busy_list_for_guest',
        'hint' => 'Контрольная выдача занятых или недоступных автомобилей.',
    ],
];

$selectedEndpoint = isset($_POST['endpoint']) && isset($endpoints[$_POST['endpoint']])
    ? (string) $_POST['endpoint']
    : 'list';
$latitudeInput = isset($_POST['latitude']) ? trim((string) $_POST['latitude']) : '55.7558';
$longitudeInput = isset($_POST['longitude']) ? trim((string) $_POST['longitude']) : '37.6173';

$requestInfo = null;
$requestError = null;
$vehicles = [];
$safeVehicles = [];
$stateSummary = [];
$stateInfoVariants = [];
$responseMeta = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $latitude = filter_var($latitudeInput, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($longitudeInput, FILTER_VALIDATE_FLOAT);

    if ($requestError === null && ($latitude === false || $latitude < -90 || $latitude > 90)) {
        $requestError = 'Широта должна быть числом от -90 до 90.';
    }
    if ($requestError === null && ($longitude === false || $longitude < -180 || $longitude > 180)) {
        $requestError = 'Долгота должна быть числом от -180 до 180.';
    }

    if ($requestError === null) {
        $endpoint = $endpoints[$selectedEndpoint];
        $formBody = http_build_query([
            'latitude' => number_format((float) $latitude, 7, '.', ''),
            'longitude' => number_format((float) $longitude, 7, '.', ''),
        ], '', '&', PHP_QUERY_RFC3986);
        $url = 'https://api.everent.me' . $endpoint['path'];

        $requestInfo = [
            'method' => 'POST',
            'endpoint' => $endpoint['path'],
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $formBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_USERAGENT => 'VoronLocalDiagnostic/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $startedAt = microtime(true);
        $responseBody = curl_exec($curl);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $responseMeta = [
            'http_status' => $httpStatus,
            'content_type' => $contentType,
            'duration_ms' => $durationMs,
            'bytes' => is_string($responseBody) ? strlen($responseBody) : 0,
        ];

        if ($responseBody === false) {
            $requestError = 'Сетевой запрос не выполнен: ' . ($curlError !== '' ? $curlError : 'неизвестная ошибка');
        } elseif (strlen($responseBody) > 5 * 1024 * 1024) {
            $requestError = 'Ответ превышает безопасный диагностический лимит 5 МБ.';
        } elseif ($httpStatus < 200 || $httpStatus >= 300) {
            $requestError = "Сервер вернул HTTP {$httpStatus}. Данные не обрабатывались.";
        } else {
            $decoded = json_decode($responseBody, true, 512);
            if (json_last_error() === JSON_ERROR_NONE) {
                collectVehicleCandidates($decoded, $vehicles);
                foreach ($vehicles as $vehicle) {
                    $safeVehicle = safeVehicle($vehicle);
                    $safeVehicles[] = $safeVehicle;

                    $state = classifyVehicleState($safeVehicle, $selectedEndpoint);
                    if (!isset($stateSummary[$state['key']])) {
                        $stateSummary[$state['key']] = [
                            'label' => $state['label'],
                            'count' => 0,
                        ];
                    }
                    $stateSummary[$state['key']]['count']++;

                    $variant = stateInfoVariant($safeVehicle);
                    if (!isset($stateInfoVariants[$variant])) {
                        $stateInfoVariants[$variant] = 0;
                    }
                    $stateInfoVariants[$variant]++;
                }
                uasort($stateSummary, function (array $left, array $right) {
                    return $right['count'] <=> $left['count'];
                });
                arsort($stateInfoVariants);
            } else {
                $requestError = 'Сервер ответил не JSON-документом. Ответ не отображается.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Диагностика статусов Voron</title>
    <style>
        :root {
            color-scheme: light;
            --page: #f4f6f8;
            --surface: #ffffff;
            --surface-soft: #f7f9fa;
            --text: #182026;
            --muted: #687680;
            --line: #dde4e8;
            --accent: #171b1f;
            --accent-hover: #343b41;
            --ok: #24714a;
            --ok-bg: #eaf7ef;
            --error: #a43636;
            --error-bg: #fff0f0;
            --radius: 16px;
            --shadow: 0 14px 36px rgba(27, 37, 44, 0.08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--page);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        input, select, button { font: inherit; }

        .page {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
            padding: 44px 0 72px;
        }

        .back {
            display: inline-block;
            margin-bottom: 24px;
            color: var(--muted);
            text-decoration: none;
        }

        .back:hover { color: var(--text); }

        .header { margin-bottom: 24px; }

        .eyebrow {
            margin: 0 0 8px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-size: clamp(30px, 5vw, 48px);
            line-height: 1.08;
            letter-spacing: -0.04em;
        }

        .intro {
            max-width: 700px;
            margin: 14px 0 0;
            color: var(--muted);
            line-height: 1.6;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow);
        }

        .form {
            display: grid;
            grid-template-columns: minmax(240px, 1.6fr) repeat(2, minmax(150px, 0.7fr)) auto;
            align-items: end;
            gap: 14px;
            padding: 20px;
        }

        .field { display: grid; gap: 7px; }

        .field label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 650;
        }

        .field input,
        .field select {
            width: 100%;
            min-height: 44px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text);
            padding: 10px 12px;
            outline: none;
        }

        .field input:focus,
        .field select:focus {
            border-color: #8f9aa2;
            box-shadow: 0 0 0 3px rgba(143, 154, 162, 0.15);
        }

        .submit {
            min-height: 44px;
            border: 0;
            border-radius: 10px;
            background: var(--accent);
            color: #fff;
            padding: 10px 18px;
            font-weight: 700;
            cursor: pointer;
        }

        .submit:hover { background: var(--accent-hover); }

        .note {
            margin: 0;
            padding: 0 20px 20px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .message {
            margin-top: 16px;
            padding: 16px 18px;
            border-radius: 12px;
        }

        .message.error {
            border: 1px solid #efbcbc;
            background: var(--error-bg);
            color: var(--error);
        }

        .message.ok {
            border: 1px solid #b9dec8;
            background: var(--ok-bg);
            color: var(--ok);
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }

        .badge {
            border: 1px solid var(--line);
            border-radius: 999px;
            background: var(--surface);
            padding: 7px 11px;
            color: var(--muted);
            font-size: 12px;
        }

        .results { margin-top: 24px; }

        .results-header {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 12px;
        }

        h2 { margin: 0; font-size: 22px; }

        .count { color: var(--muted); font-size: 14px; }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .summary-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--surface);
            padding: 14px 16px;
        }

        .summary-card strong {
            display: block;
            margin-bottom: 4px;
            font-size: 24px;
        }

        .summary-card span {
            color: var(--muted);
            font-size: 13px;
        }

        .summary-note {
            margin: 0 0 16px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .variants {
            margin: 0 0 16px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--surface);
        }

        .variants summary {
            cursor: pointer;
            padding: 14px 16px;
            font-weight: 650;
        }

        .variants-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 8px;
            padding: 0 16px 16px;
        }

        .variant-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            border-top: 1px solid var(--line);
            padding-top: 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .variant-row b { color: var(--text); }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            white-space: nowrap;
        }

        th {
            background: var(--surface-soft);
            color: var(--muted);
            font-size: 11px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        tr:last-child td { border-bottom: 0; }

        .status {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            border-radius: 999px;
            background: var(--surface-soft);
            padding: 5px 8px;
        }

        details.safe-json {
            margin-top: 16px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--surface);
        }

        details.safe-json summary {
            cursor: pointer;
            padding: 14px 16px;
            font-weight: 650;
        }

        pre {
            max-height: 420px;
            overflow: auto;
            margin: 0;
            padding: 16px;
            border-top: 1px solid var(--line);
            background: #11171b;
            color: #d8e0e5;
            font: 12px/1.55 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        @media (max-width: 820px) {
            .page { width: min(100% - 20px, 1120px); padding-top: 28px; }
            .form { grid-template-columns: 1fr 1fr; }
            .field.endpoint, .submit { grid-column: 1 / -1; }
        }

        @media (max-width: 520px) {
            .form { grid-template-columns: 1fr; }
            .field.endpoint, .submit { grid-column: auto; }
        }
    </style>
</head>
<body>
<main class="page">
    <a class="back" href="index.php">← Вернуться в каталог</a>
    <a class="back" href="collector.php" style="margin-left: 16px;">Сбор в базу →</a>

    <header class="header">
        <p class="eyebrow">Voron · ручная проверка</p>
        <h1>Диагностика статусов</h1>
        <p class="intro">Страница выполняет только один гостевой запрос после нажатия кнопки. Результат не записывается в базу или файлы, а потенциально лишние поля ответа не отображаются.</p>
    </header>

    <section class="panel" aria-label="Параметры запроса">
        <form class="form" method="post">
            <div class="field endpoint">
                <label for="endpoint">Выдача API</label>
                <select id="endpoint" name="endpoint">
                    <?php foreach ($endpoints as $key => $endpoint): ?>
                        <option value="<?= e($key) ?>" <?= $selectedEndpoint === $key ? 'selected' : '' ?>>
                            <?= e($endpoint['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="latitude">Широта</label>
                <input id="latitude" name="latitude" inputmode="decimal" required value="<?= e($latitudeInput) ?>">
            </div>

            <div class="field">
                <label for="longitude">Долгота</label>
                <input id="longitude" name="longitude" inputmode="decimal" required value="<?= e($longitudeInput) ?>">
            </div>

            <button class="submit" type="submit">Выполнить один запрос</button>
        </form>

        <p class="note" id="endpoint-hint"><?= e($endpoints[$selectedEndpoint]['hint']) ?> Координаты используются только как область поиска и не сохраняются. Никакие телефон, ключ, токен или ID устройства не передаются.</p>
    </section>

    <?php if ($requestError !== null): ?>
        <div class="message error" role="alert"><?= e($requestError) ?></div>
    <?php elseif ($requestInfo !== null): ?>
        <div class="message ok">Запрос выполнен. В базу данных и файлы ничего не записано.</div>
    <?php endif; ?>

    <?php if ($responseMeta !== null): ?>
        <div class="meta" aria-label="Технические сведения об ответе">
            <span class="badge">HTTP <?= (int) $responseMeta['http_status'] ?></span>
            <span class="badge"><?= (int) $responseMeta['duration_ms'] ?> мс</span>
            <span class="badge"><?= number_format((int) $responseMeta['bytes'], 0, ',', ' ') ?> байт</span>
            <?php if ($requestInfo !== null): ?>
                <span class="badge"><?= e($requestInfo['method'] . ' ' . $requestInfo['endpoint']) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($requestInfo !== null && $requestError === null): ?>
        <section class="results">
            <div class="results-header">
                <h2>Найденные автомобили</h2>
                <span class="count"><?= count($safeVehicles) ?> записей</span>
            </div>

            <?php if ($safeVehicles === []): ?>
                <div class="message error">JSON получен, но объекты автомобилей по известным полям не найдены. Возможно, API ожидает дополнительные фильтры или использует другую структуру ответа.</div>
            <?php else: ?>
                <div class="summary-grid" aria-label="Сводка состояний автомобилей">
                    <?php foreach ($stateSummary as $state): ?>
                        <div class="summary-card">
                            <strong><?= (int) $state['count'] ?></strong>
                            <span><?= e($state['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p class="summary-note">
                    Сводка является интерпретацией ответа API: «Оплачено до …» и «Поминутный тариф» считаются арендой,
                    а автомобиль из списка занятых без пояснения — занятым с неизвестной причиной.
                </p>

                <details class="variants">
                    <summary>Фактические варианты stateInfo (<?= count($stateInfoVariants) ?>)</summary>
                    <div class="variants-list">
                        <?php foreach ($stateInfoVariants as $variant => $count): ?>
                            <div class="variant-row">
                                <span><?= e($variant) ?></span>
                                <b><?= (int) $count ?></b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Категория</th>
                            <th>Статус</th>
                            <th>stateInfo</th>
                            <th>stateTime</th>
                            <th>serviceMode</th>
                            <th>isAllocated</th>
                            <th>inGarage</th>
                            <th>Топливо</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($safeVehicles as $vehicle): ?>
                            <tr>
                                <td><?= e((string) ($vehicle['id'] ?? '')) ?></td>
                                <td><?= e((string) ($vehicle['title'] ?? '—')) ?></td>
                                <td><?= e((string) ($vehicle['categoryTitle'] ?? $vehicle['baseCategoryTitle'] ?? '—')) ?></td>
                                <td>
                                    <span class="status">
                                        <?= e((string) ($vehicle['status'] ?? '—')) ?> · <?= e(statusLabel($vehicle['status'] ?? null)) ?>
                                    </span>
                                </td>
                                <td><?= e((string) ($vehicle['stateInfo'] ?? '—')) ?></td>
                                <td><?= e((string) ($vehicle['stateTime'] ?? '—')) ?></td>
                                <td><?= e((string) ($vehicle['serviceMode'] ?? '—')) ?></td>
                                <td><?= e(var_export($vehicle['isAllocated'] ?? null, true)) ?></td>
                                <td><?= e(var_export($vehicle['inGarage'] ?? null, true)) ?></td>
                                <td><?= e((string) ($vehicle['fuel'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <details class="safe-json">
                    <summary>Показать обезличенный JSON</summary>
                    <pre><?= e(json_encode($safeVehicles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]') ?></pre>
                </details>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script>
    const hints = <?= json_encode(array_map(function (array $endpoint) {
        return $endpoint['hint'];
    }, $endpoints), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const endpointSelect = document.getElementById('endpoint');
    const hint = document.getElementById('endpoint-hint');

    endpointSelect.addEventListener('change', function () {
        hint.textContent = hints[endpointSelect.value] + ' Координаты используются только как область поиска и не сохраняются. Никакие телефон, ключ, токен или ID устройства не передаются.';
    });
</script>
</body>
</html>
