<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatAnalyticsDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '—';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

    return $date instanceof DateTimeImmutable ? $date->format('d.m.Y H:i') : $value;
}

function dayCountLabel(int $days): string
{
    $lastTwoDigits = $days % 100;
    $lastDigit = $days % 10;

    if ($lastTwoDigits >= 11 && $lastTwoDigits <= 14) {
        $word = 'дней';
    } elseif ($lastDigit === 1) {
        $word = 'день';
    } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
        $word = 'дня';
    } else {
        $word = 'дней';
    }

    return $days . ' ' . $word;
}

function demandLevel(int $rentalStarts, int $maximum): string
{
    if ($rentalStarts === 0) {
        return 'low';
    }
    if ($maximum <= 1 || $rentalStarts / $maximum >= 0.67) {
        return 'high';
    }

    return 'medium';
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

$allowedPeriods = ['7', '30', 'all'];
$period = isset($_GET['period']) && in_array((string) $_GET['period'], $allowedPeriods, true)
    ? (string) $_GET['period']
    : '30';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

$errorMessage = null;
$categories = [];
$statuses = [];
$vehicles = [];
$categoryStats = [];
$brandStats = [];
$statisticsDays = 0;
$summary = [
    'vehicles' => 0,
    'free_now' => 0,
    'rented_now' => 0,
    'rental_started' => 0,
    'returned_free' => 0,
    'left_free' => 0,
];

try {
    $pdo = database();
    $statisticsDays = (int) $pdo->query(
        "SELECT COALESCE(TIMESTAMPDIFF(DAY, MIN(started_at), NOW()) + 1, 0)
         FROM collector_runs
         WHERE run_status = 'success'"
    )->fetchColumn();
    $categories = $pdo->query(
        "SELECT DISTINCT category_title
         FROM vehicles
         WHERE category_title IS NOT NULL AND category_title <> ''
         ORDER BY category_title"
    )->fetchAll(PDO::FETCH_COLUMN);
    $statuses = $pdo->query(
        'SELECT slug, name FROM statuses WHERE is_active = 1 ORDER BY sort_order, id'
    )->fetchAll();

    $periodSql = '';
    $periodParams = [];
    if ($period !== 'all') {
        $since = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))
            ->modify('-' . (int) $period . ' days')
            ->format('Y-m-d H:i:s');
        $periodSql = 'WHERE e.detected_at >= :event_since';
        $periodParams['event_since'] = $since;
    }

    $eventStatsSql =
        'SELECT
            e.vehicle_id,
            SUM(e.event_type = \'rental_started\') AS rental_starts,
            SUM(e.event_type = \'returned_free\') AS returns_count,
            SUM(fs.slug = \'free\' AND ts.slug <> \'free\') AS left_free_count,
            MAX(CASE WHEN e.event_type = \'rental_started\' THEN e.detected_at END) AS last_rental_at
         FROM vehicle_status_events e
         JOIN statuses fs ON fs.id = e.from_status_id
         JOIN statuses ts ON ts.id = e.to_status_id
         ' . $periodSql . '
         GROUP BY e.vehicle_id';

    $vehicleWhere = ['v.is_active = 1'];
    $vehicleParams = $periodParams;
    if ($category !== '') {
        $vehicleWhere[] = 'v.category_title = :category';
        $vehicleParams['category'] = $category;
    }
    if ($status !== '') {
        $vehicleWhere[] = 's.slug = :status';
        $vehicleParams['status'] = $status;
    }
    if ($search !== '') {
        $vehicleWhere[] = '(v.title LIKE :search_title OR v.category_title LIKE :search_category OR CAST(v.external_id AS CHAR) LIKE :search_id)';
        $vehicleParams['search_title'] = '%' . $search . '%';
        $vehicleParams['search_category'] = '%' . $search . '%';
        $vehicleParams['search_id'] = '%' . $search . '%';
    }

    $vehicleSql =
        'SELECT
            v.external_id,
            v.title,
            v.category_title,
            v.new_until,
            s.slug AS status_slug,
            s.name AS status_name,
            v.state_info,
            v.state_started_at,
            COALESCE(es.rental_starts, 0) AS rental_starts,
            COALESCE(es.returns_count, 0) AS returns_count,
            COALESCE(es.left_free_count, 0) AS left_free_count,
            es.last_rental_at
         FROM vehicles v
         LEFT JOIN statuses s ON s.id = v.current_status_id
         LEFT JOIN (' . $eventStatsSql . ') es ON es.vehicle_id = v.id
         WHERE ' . implode(' AND ', $vehicleWhere) . '
         ORDER BY rental_starts DESC, left_free_count DESC, returns_count DESC, v.category_title, v.title, v.external_id';
    $vehicleStatement = $pdo->prepare($vehicleSql);
    $vehicleStatement->execute($vehicleParams);
    $vehicles = $vehicleStatement->fetchAll();

    $currentSummary = $pdo->query(
        "SELECT
            COUNT(*) AS vehicles,
            SUM(s.slug = 'free') AS free_now,
            SUM(s.slug IN ('rented_fixed', 'rented_minute', 'occupied', 'reserved')) AS rented_now
         FROM vehicles v
         LEFT JOIN statuses s ON s.id = v.current_status_id
         WHERE v.is_active = 1"
    )->fetch();
    $summary['vehicles'] = (int) ($currentSummary['vehicles'] ?? 0);
    $summary['free_now'] = (int) ($currentSummary['free_now'] ?? 0);
    $summary['rented_now'] = (int) ($currentSummary['rented_now'] ?? 0);

    $eventSummarySql =
        "SELECT
            SUM(e.event_type = 'rental_started') AS rental_started,
            SUM(e.event_type = 'returned_free') AS returned_free,
            SUM(fs.slug = 'free' AND ts.slug <> 'free') AS left_free
         FROM vehicle_status_events e
         JOIN statuses fs ON fs.id = e.from_status_id
         JOIN statuses ts ON ts.id = e.to_status_id
         {$periodSql}";
    $eventSummaryStatement = $pdo->prepare($eventSummarySql);
    $eventSummaryStatement->execute($periodParams);
    $eventSummary = $eventSummaryStatement->fetch();
    $summary['rental_started'] = (int) ($eventSummary['rental_started'] ?? 0);
    $summary['returned_free'] = (int) ($eventSummary['returned_free'] ?? 0);
    $summary['left_free'] = (int) ($eventSummary['left_free'] ?? 0);

    $categorySql =
        "SELECT
            COALESCE(NULLIF(v.base_category_title, ''), 'Без категории') AS category_title,
            COUNT(*) AS vehicles_count,
            SUM(COALESCE(es.rental_starts, 0)) AS rental_starts,
            SUM(COALESCE(es.returns_count, 0)) AS returns_count,
            SUM(COALESCE(es.left_free_count, 0)) AS left_free_count,
            SUM(s.slug = 'free') AS free_now,
            SUM(s.slug IN ('rented_fixed', 'rented_minute', 'occupied', 'reserved')) AS rented_now,
            MAX(es.last_rental_at) AS last_rental_at
         FROM vehicles v
         LEFT JOIN statuses s ON s.id = v.current_status_id
         LEFT JOIN ({$eventStatsSql}) es ON es.vehicle_id = v.id
         WHERE v.is_active = 1
         GROUP BY COALESCE(NULLIF(v.base_category_title, ''), 'Без категории')
         ORDER BY rental_starts DESC, rented_now DESC, category_title
         LIMIT 100";
    $categoryStatement = $pdo->prepare($categorySql);
    $categoryStatement->execute($periodParams);
    $categoryStats = $categoryStatement->fetchAll();

    $brandSql =
        "SELECT
            COALESCE(b.name, 'Без марки') AS brand_name,
            COUNT(*) AS vehicles_count,
            SUM(COALESCE(es.rental_starts, 0)) AS rental_starts,
            SUM(COALESCE(es.returns_count, 0)) AS returns_count,
            SUM(COALESCE(es.left_free_count, 0)) AS left_free_count,
            SUM(s.slug = 'free') AS free_now,
            SUM(s.slug IN ('rented_fixed', 'rented_minute', 'occupied', 'reserved')) AS rented_now,
            MAX(es.last_rental_at) AS last_rental_at
         FROM vehicles v
         LEFT JOIN models m ON m.name = v.category_title
         LEFT JOIN brands b ON b.id = m.brand_id
         LEFT JOIN statuses s ON s.id = v.current_status_id
         LEFT JOIN ({$eventStatsSql}) es ON es.vehicle_id = v.id
         WHERE v.is_active = 1
         GROUP BY COALESCE(b.name, 'Без марки')
         ORDER BY rental_starts DESC, rented_now DESC, brand_name
         LIMIT 100";
    $brandStatement = $pdo->prepare($brandSql);
    $brandStatement->execute($periodParams);
    $brandStats = $brandStatement->fetchAll();
} catch (Throwable $exception) {
    error_log('Voron analytics error: ' . $exception->getMessage());
    $errorMessage = 'Не удалось загрузить аналитику. Проверьте структуру базы данных.';
}

$maximumRentalStarts = 0;
foreach ($vehicles as $vehicle) {
    $maximumRentalStarts = max($maximumRentalStarts, (int) $vehicle['rental_starts']);
}

$maximumCategoryRentalStarts = 0;
foreach ($categoryStats as $categoryRow) {
    $maximumCategoryRentalStarts = max($maximumCategoryRentalStarts, (int) $categoryRow['rental_starts']);
}

$maximumBrandRentalStarts = 0;
foreach ($brandStats as $brandRow) {
    $maximumBrandRentalStarts = max($maximumBrandRentalStarts, (int) $brandRow['rental_starts']);
}

$demandLabels = [
    'high' => 'Высокий спрос',
    'medium' => 'Есть спрос',
    'low' => 'Пока без аренд',
];
$nowSql = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))
    ->format('Y-m-d H:i:s');
$periodLabels = ['7' => '7 дней', '30' => '30 дней', 'all' => 'Всё время'];
$hasActiveVehicleFilters = $period !== '30' || $category !== '' || $status !== '' || $search !== '';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Аналитика аренды Voron</title>
    <style>
        :root { --page:#f4f6f8; --surface:#fff; --soft:#f8fafb; --text:#182026; --muted:#687680; --line:#dde4e8; --accent:#171b1f; --green:#24714a; --blue:#315d9b; --orange:#986620; --red:#a43636; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:var(--page); color:var(--text); font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        .page { width:min(1180px,calc(100% - 32px)); margin:0 auto; padding:48px 0 72px; }
        header { display:flex; justify-content:space-between; gap:24px; align-items:end; margin:28px 0 24px; }
        h1 { margin:0; font-size:clamp(32px,5vw,52px); letter-spacing:-.045em; }
        .intro { max-width:700px; margin:10px 0 0; color:var(--muted); line-height:1.55; }
        .filter-panel { margin-top:12px; }
        .filter-panel summary { display:inline-flex; color:var(--blue); font-size:13px; font-weight:700; cursor:pointer; list-style:none; text-decoration:underline; text-decoration-color:#bdcbe0; text-underline-offset:3px; }
        .filter-panel summary::-webkit-details-marker { display:none; }
        .filter-panel summary:hover { color:#214977; }
        .filter-panel .close-label { display:none; }
        .filter-panel[open] .open-label { display:none; }
        .filter-panel[open] .close-label { display:inline; }
        .filters { display:grid; grid-template-columns:150px 1fr 1fr 1.3fr auto; gap:10px; align-items:end; margin-top:10px; border:1px solid var(--line); border-radius:16px; background:var(--surface); padding:16px; }
        label { display:block; margin:0 0 5px; color:var(--muted); font-size:11px; font-weight:700; text-transform:uppercase; }
        select,input { width:100%; min-width:0; border:1px solid var(--line); border-radius:10px; background:#fff; padding:10px 11px; font:inherit; }
        button { border:0; border-radius:10px; background:var(--accent); color:#fff; padding:11px 16px; font:inherit; font-weight:700; cursor:pointer; }
        .cards { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin:16px 0 24px; }
        .card { display:flex; flex-direction:column; align-items:center; justify-content:center; border:1px solid var(--line); border-radius:14px; background:var(--surface); padding:13px; text-align:center; }
        .card strong { display:block; font-size:22px; line-height:1; }
        .card span { display:block; margin-top:6px; color:var(--muted); font-size:12px; line-height:1.3; }
        .section { margin-top:22px; }
        .section-head { display:flex; justify-content:space-between; gap:16px; align-items:end; margin-bottom:10px; }
        .section-copy { display:grid; gap:5px; }
        h2 { margin:0; font-size:22px; }
        .hint { color:var(--muted); font-size:13px; }
        .table-wrap { overflow:auto; border:1px solid var(--line); border-radius:16px; background:var(--surface); }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th,td { padding:11px 13px; border-bottom:1px solid var(--line); text-align:left; white-space:nowrap; }
        th { position:sticky; top:0; z-index:2; background:var(--soft); color:var(--muted); font-size:10px; letter-spacing:.04em; text-transform:uppercase; }
        tr:last-child td { border-bottom:0; }
        .number { font-weight:750; font-variant-numeric:tabular-nums; }
        .status { display:inline-block; border-radius:999px; background:var(--soft); padding:5px 8px; }
        .status.free { color:var(--green); } .status.rented_fixed,.status.rented_minute { color:var(--blue); } .status.maintenance { color:var(--orange); }
        .vehicle-table tbody tr { transition:background-color .15s ease; }
        .vehicle-table tbody tr.demand-high { background:#f0f7f2; }
        .vehicle-table tbody tr.demand-medium { background:#f7f8f2; }
        .vehicle-table tbody tr.demand-low { background:#fdf4f3; }
        .vehicle-table tbody tr:hover { box-shadow:inset 0 0 0 9999px rgba(255,255,255,.35); }
        .vehicle-table tbody tr.demand-high td:first-child { border-left:3px solid #9bc7a9; }
        .vehicle-table tbody tr.demand-medium td:first-child { border-left:3px solid #c8cfac; }
        .vehicle-table tbody tr.demand-low td:first-child { border-left:3px solid #d9aaa2; }
        .rank { color:var(--muted); text-align:right; }
        .demand { display:inline-flex; align-items:center; gap:7px; border:1px solid transparent; border-radius:999px; padding:5px 9px; font-size:12px; font-weight:700; }
        .demand::before { width:7px; height:7px; border-radius:50%; background:currentColor; content:""; opacity:.65; }
        .demand.high { border-color:#cfe3d5; background:#f5faf6; color:#38724c; }
        .demand.medium { border-color:#dde1ca; background:#fafbf5; color:#707a45; }
        .demand.low { border-color:#ebd0cb; background:#fff8f7; color:#95564d; }
        .new-badge { display:inline-flex; margin-left:7px; border:1px solid #cadbeb; border-radius:999px; background:#f3f8fc; color:#426c8d; padding:3px 7px; font-size:10px; font-weight:800; letter-spacing:.03em; text-transform:uppercase; vertical-align:middle; }
        .legend { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0 0; }
        .legend .demand { font-weight:600; }
        .empty,.error { border-radius:14px; padding:20px; text-align:center; }
        .empty { border:1px dashed var(--line); background:var(--surface); color:var(--muted); }
        .error { border:1px solid #efbcbc; background:#fff0f0; color:var(--red); }
        @media(max-width:980px){ .cards{grid-template-columns:repeat(3,1fr)} .filters{grid-template-columns:1fr 1fr} .filters button{grid-column:1/-1} }
        @media(max-width:620px){ .page{width:min(100% - 20px,1180px);padding-top:28px} header{display:block}.cards{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr} }
    </style>
</head>
<body>
<main class="page">
    <header>
        <h1>Аналитика аренды</h1>
    </header>

    <?php if ($errorMessage !== null): ?>
        <div class="error" role="alert"><?= e($errorMessage) ?></div>
    <?php else: ?>
        <section class="cards" aria-label="Основные показатели">
            <div class="card"><strong><?= $summary['vehicles'] ?></strong><span>автомобилей в базе</span></div>
            <div class="card"><strong><?= $summary['free_now'] ?></strong><span>свободны сейчас</span></div>
            <div class="card"><strong><?= $summary['rented_now'] ?></strong><span>арендованы сейчас</span></div>
            <div class="card"><strong><?= $summary['rental_started'] ?></strong><span>подтверждённых аренд за период</span></div>
            <div class="card"><strong><?= $summary['returned_free'] ?></strong><span>возвращений в свободные</span></div>
            <div class="card"><strong><?= $summary['left_free'] ?></strong><span>исчезновений из свободных</span></div>
        </section>

        <?php if ($summary['rental_started'] === 0 && $summary['returned_free'] === 0): ?>
            <div class="empty">Пока есть только исходный снимок. Переходы и рейтинг появятся после следующих сборов, когда статусы начнут меняться.</div>
        <?php endif; ?>

        <section class="section">
            <div class="section-head">
                <div class="section-copy">
                    <h2>Спрос по автомобилям</h2>
                    <span class="hint">Сначала наиболее востребованные · <?= $statisticsDays > 0 ? 'Рейтинг за весь период (' . e(dayCountLabel($statisticsDays)) . ')' : 'Статистика ещё не собиралась' ?></span>
                </div>
            </div>
            <details class="filter-panel"<?= $hasActiveVehicleFilters ? ' open' : '' ?>>
                <summary>
                    <span class="open-label">Раскрыть фильтры поиска</span>
                    <span class="close-label">Скрыть фильтры поиска</span>
                </summary>
                <form class="filters" method="get">
                    <div><label for="period">Период</label><select id="period" name="period"><?php foreach ($periodLabels as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= $period === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div><label for="category">Модель</label><select id="category" name="category"><option value="">Все</option><?php foreach ($categories as $item): ?><option value="<?= e((string) $item) ?>" <?= $category === $item ? 'selected' : '' ?>><?= e((string) $item) ?></option><?php endforeach; ?></select></div>
                    <div><label for="status">Текущий статус</label><select id="status" name="status"><option value="">Все</option><?php foreach ($statuses as $item): ?><option value="<?= e((string) $item['slug']) ?>" <?= $status === $item['slug'] ? 'selected' : '' ?>><?= e((string) $item['name']) ?></option><?php endforeach; ?></select></div>
                    <div><label for="search">Поиск</label><input id="search" name="search" value="<?= e($search) ?>" placeholder="Название или модель"></div>
                    <button type="submit">Применить</button>
                </form>
            </details>
            <div class="legend" aria-label="Обозначения спроса">
                <span class="demand high">Высокий спрос</span>
                <span class="demand medium">Есть спрос</span>
                <span class="demand low">Пока без аренд</span>
            </div>
            <div class="table-wrap" style="margin-top:12px">
                <table class="vehicle-table">
                    <thead><tr><th>#</th><th>Автомобиль</th><th>Спрос</th><th>Модель</th><th>Сейчас</th><th>Аренд</th><th>Уходов из свободных</th><th>Возвратов</th><th>Последняя аренда</th></tr></thead>
                    <tbody>
                    <?php foreach ($vehicles as $rank => $vehicle): ?>
                        <?php $demandLevel = demandLevel((int) $vehicle['rental_starts'], $maximumRentalStarts); ?>
                        <tr class="demand-<?= e($demandLevel) ?>">
                            <td class="rank number"><?= $rank + 1 ?></td>
                            <td>
                                <?= e((string) ($vehicle['title'] ?: '—')) ?>
                                <?php if ($vehicle['new_until'] !== null && (string) $vehicle['new_until'] >= $nowSql): ?>
                                    <span class="new-badge">Новинка</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="demand <?= e($demandLevel) ?>"><?= e($demandLabels[$demandLevel]) ?></span></td>
                            <td><?= e((string) ($vehicle['category_title'] ?: '—')) ?></td>
                            <td><span class="status <?= e((string) $vehicle['status_slug']) ?>"><?= e((string) ($vehicle['status_name'] ?: 'Неизвестен')) ?></span></td>
                            <td class="number"><?= (int) $vehicle['rental_starts'] ?></td>
                            <td class="number"><?= (int) $vehicle['left_free_count'] ?></td>
                            <td class="number"><?= (int) $vehicle['returns_count'] ?></td>
                            <td><?= e(formatAnalyticsDate($vehicle['last_rental_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section">
            <div class="section-head">
                <div class="section-copy">
                    <h2>Спрос по категориям</h2>
                    <span class="hint">Купе, седаны, кабриолеты и другие виды транспорта</span>
                </div>
                <span class="hint"><?= count($categoryStats) ?> категорий</span>
            </div>
            <div class="table-wrap" style="margin-top:12px">
                <table class="vehicle-table">
                    <thead><tr><th>#</th><th>Категория</th><th>Спрос</th><th>Автомобилей</th><th>Свободны сейчас</th><th>Арендованы сейчас</th><th>Аренд</th><th>Уходов из свободных</th><th>Возвратов</th><th>Последняя аренда</th></tr></thead>
                    <tbody>
                    <?php foreach ($categoryStats as $rank => $categoryRow): ?>
                        <?php $categoryDemandLevel = demandLevel((int) $categoryRow['rental_starts'], $maximumCategoryRentalStarts); ?>
                        <tr class="demand-<?= e($categoryDemandLevel) ?>">
                            <td class="rank number"><?= $rank + 1 ?></td>
                            <td><?= e((string) $categoryRow['category_title']) ?></td>
                            <td><span class="demand <?= e($categoryDemandLevel) ?>"><?= e($demandLabels[$categoryDemandLevel]) ?></span></td>
                            <td class="number"><?= (int) $categoryRow['vehicles_count'] ?></td>
                            <td class="number"><?= (int) $categoryRow['free_now'] ?></td>
                            <td class="number"><?= (int) $categoryRow['rented_now'] ?></td>
                            <td class="number"><?= (int) $categoryRow['rental_starts'] ?></td>
                            <td class="number"><?= (int) $categoryRow['left_free_count'] ?></td>
                            <td class="number"><?= (int) $categoryRow['returns_count'] ?></td>
                            <td><?= e(formatAnalyticsDate($categoryRow['last_rental_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section">
            <div class="section-head">
                <div class="section-copy">
                    <h2>Спрос по маркам авто</h2>
                    <span class="hint">Сводный рейтинг BMW, Mercedes-Benz, Audi и других марок</span>
                </div>
                <span class="hint"><?= count($brandStats) ?> марок</span>
            </div>
            <div class="table-wrap" style="margin-top:12px">
                <table class="vehicle-table">
                    <thead><tr><th>#</th><th>Марка</th><th>Спрос</th><th>Автомобилей</th><th>Свободны сейчас</th><th>Арендованы сейчас</th><th>Аренд</th><th>Уходов из свободных</th><th>Возвратов</th><th>Последняя аренда</th></tr></thead>
                    <tbody>
                    <?php foreach ($brandStats as $rank => $brandRow): ?>
                        <?php $brandDemandLevel = demandLevel((int) $brandRow['rental_starts'], $maximumBrandRentalStarts); ?>
                        <tr class="demand-<?= e($brandDemandLevel) ?>">
                            <td class="rank number"><?= $rank + 1 ?></td>
                            <td><?= e((string) $brandRow['brand_name']) ?></td>
                            <td><span class="demand <?= e($brandDemandLevel) ?>"><?= e($demandLabels[$brandDemandLevel]) ?></span></td>
                            <td class="number"><?= (int) $brandRow['vehicles_count'] ?></td>
                            <td class="number"><?= (int) $brandRow['free_now'] ?></td>
                            <td class="number"><?= (int) $brandRow['rented_now'] ?></td>
                            <td class="number"><?= (int) $brandRow['rental_starts'] ?></td>
                            <td class="number"><?= (int) $brandRow['left_free_count'] ?></td>
                            <td class="number"><?= (int) $brandRow['returns_count'] ?></td>
                            <td><?= e(formatAnalyticsDate($brandRow['last_rental_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
