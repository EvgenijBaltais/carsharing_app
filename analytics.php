<?php

declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function database(): PDO
{
    $host = getenv('VORON_DB_HOST') ?: '127.0.0.1';
    $port = getenv('VORON_DB_PORT') ?: '3306';
    $name = getenv('VORON_DB_NAME') ?: 'voron';
    $user = getenv('VORON_DB_USER') ?: 'root';
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
$models = [];
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
        $vehicleWhere[] = '(v.title LIKE :search OR v.category_title LIKE :search OR CAST(v.external_id AS CHAR) LIKE :search)';
        $vehicleParams['search'] = '%' . $search . '%';
    }

    $vehicleSql =
        'SELECT
            v.external_id,
            v.title,
            v.category_title,
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
         ORDER BY rental_starts DESC, left_free_count DESC, returns_count DESC, v.category_title, v.title, v.external_id
         LIMIT 500';
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

    $modelSql =
        "SELECT
            COALESCE(NULLIF(v.category_title, ''), 'Без категории') AS category_title,
            COUNT(*) AS vehicles_count,
            SUM(COALESCE(es.rental_starts, 0)) AS rental_starts,
            SUM(COALESCE(es.returns_count, 0)) AS returns_count,
            SUM(s.slug = 'free') AS free_now,
            SUM(s.slug IN ('rented_fixed', 'rented_minute', 'occupied', 'reserved')) AS rented_now
         FROM vehicles v
         LEFT JOIN statuses s ON s.id = v.current_status_id
         LEFT JOIN ({$eventStatsSql}) es ON es.vehicle_id = v.id
         WHERE v.is_active = 1
         GROUP BY COALESCE(NULLIF(v.category_title, ''), 'Без категории')
         ORDER BY rental_starts DESC, rented_now DESC, category_title
         LIMIT 100";
    $modelStatement = $pdo->prepare($modelSql);
    $modelStatement->execute($periodParams);
    $models = $modelStatement->fetchAll();
} catch (Throwable $exception) {
    error_log('Voron analytics error: ' . $exception->getMessage());
    $errorMessage = 'Не удалось загрузить аналитику. Проверьте структуру базы данных.';
}

$periodLabels = ['7' => '7 дней', '30' => '30 дней', 'all' => 'Всё время'];
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
        .back { color:var(--muted); text-decoration:none; font-size:14px; }
        header { display:flex; justify-content:space-between; gap:24px; align-items:end; margin:28px 0 24px; }
        h1 { margin:0; font-size:clamp(32px,5vw,52px); letter-spacing:-.045em; }
        .intro { max-width:700px; margin:10px 0 0; color:var(--muted); line-height:1.55; }
        .collector-link { border-radius:999px; background:var(--accent); color:#fff; padding:10px 16px; text-decoration:none; white-space:nowrap; }
        .filters { display:grid; grid-template-columns:150px 1fr 1fr 1.3fr auto; gap:10px; align-items:end; border:1px solid var(--line); border-radius:16px; background:var(--surface); padding:16px; }
        label { display:block; margin:0 0 5px; color:var(--muted); font-size:11px; font-weight:700; text-transform:uppercase; }
        select,input { width:100%; min-width:0; border:1px solid var(--line); border-radius:10px; background:#fff; padding:10px 11px; font:inherit; }
        button { border:0; border-radius:10px; background:var(--accent); color:#fff; padding:11px 16px; font:inherit; font-weight:700; cursor:pointer; }
        .cards { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin:16px 0 24px; }
        .card { border:1px solid var(--line); border-radius:14px; background:var(--surface); padding:16px; }
        .card strong { display:block; font-size:26px; line-height:1; }
        .card span { display:block; margin-top:7px; color:var(--muted); font-size:12px; line-height:1.35; }
        .section { margin-top:22px; }
        .section-head { display:flex; justify-content:space-between; gap:16px; align-items:end; margin-bottom:10px; }
        h2 { margin:0; font-size:22px; }
        .hint { color:var(--muted); font-size:13px; }
        .table-wrap { overflow:auto; border:1px solid var(--line); border-radius:16px; background:var(--surface); }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th,td { padding:11px 13px; border-bottom:1px solid var(--line); text-align:left; white-space:nowrap; }
        th { background:var(--soft); color:var(--muted); font-size:10px; letter-spacing:.04em; text-transform:uppercase; }
        tr:last-child td { border-bottom:0; }
        .number { font-weight:750; font-variant-numeric:tabular-nums; }
        .status { display:inline-block; border-radius:999px; background:var(--soft); padding:5px 8px; }
        .status.free { color:var(--green); } .status.rented_fixed,.status.rented_minute { color:var(--blue); } .status.maintenance { color:var(--orange); }
        .empty,.error { border-radius:14px; padding:20px; text-align:center; }
        .empty { border:1px dashed var(--line); background:var(--surface); color:var(--muted); }
        .error { border:1px solid #efbcbc; background:#fff0f0; color:var(--red); }
        @media(max-width:980px){ .cards{grid-template-columns:repeat(3,1fr)} .filters{grid-template-columns:1fr 1fr} .filters button{grid-column:1/-1} }
        @media(max-width:620px){ .page{width:min(100% - 20px,1180px);padding-top:28px} header{display:block}.collector-link{display:inline-block;margin-top:16px}.cards{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr} }
    </style>
</head>
<body>
<main class="page">
    <a class="back" href="index.php">← Вернуться в каталог</a>
    <header>
        <div>
            <h1>Аналитика аренды</h1>
            <p class="intro">Рейтинг строится по переходам между состояниями. Первое появление автомобиля считается исходной точкой и не увеличивает число заказов.</p>
        </div>
        <a class="collector-link" href="collector.php">Собрать новый снимок</a>
    </header>

    <?php if ($errorMessage !== null): ?>
        <div class="error" role="alert"><?= e($errorMessage) ?></div>
    <?php else: ?>
        <form class="filters" method="get">
            <div><label for="period">Период</label><select id="period" name="period"><?php foreach ($periodLabels as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= $period === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div><label for="category">Модель / категория</label><select id="category" name="category"><option value="">Все</option><?php foreach ($categories as $item): ?><option value="<?= e((string) $item) ?>" <?= $category === $item ? 'selected' : '' ?>><?= e((string) $item) ?></option><?php endforeach; ?></select></div>
            <div><label for="status">Текущий статус</label><select id="status" name="status"><option value="">Все</option><?php foreach ($statuses as $item): ?><option value="<?= e((string) $item['slug']) ?>" <?= $status === $item['slug'] ? 'selected' : '' ?>><?= e((string) $item['name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="search">Поиск</label><input id="search" name="search" value="<?= e($search) ?>" placeholder="ID, название или категория"></div>
            <button type="submit">Применить</button>
        </form>

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
            <div class="section-head"><h2>Конкретные автомобили</h2><span class="hint">До 500 записей · <?= e($periodLabels[$period]) ?></span></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>Автомобиль</th><th>Категория</th><th>Сейчас</th><th>Аренд</th><th>Уходов из свободных</th><th>Возвратов</th><th>Последняя аренда</th></tr></thead>
                    <tbody>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <tr>
                            <td class="number"><?= (int) $vehicle['external_id'] ?></td>
                            <td><?= e((string) ($vehicle['title'] ?: '—')) ?></td>
                            <td><?= e((string) ($vehicle['category_title'] ?: '—')) ?></td>
                            <td><span class="status <?= e((string) $vehicle['status_slug']) ?>"><?= e((string) ($vehicle['status_name'] ?: 'Неизвестен')) ?></span></td>
                            <td class="number"><?= (int) $vehicle['rental_starts'] ?></td>
                            <td class="number"><?= (int) $vehicle['left_free_count'] ?></td>
                            <td class="number"><?= (int) $vehicle['returns_count'] ?></td>
                            <td><?= e((string) ($vehicle['last_rental_at'] ?: '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section">
            <div class="section-head"><h2>Категории и модели</h2><span class="hint">Суммарно по автомобилям</span></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Категория</th><th>Автомобилей</th><th>Аренд</th><th>Возвратов</th><th>Свободны сейчас</th><th>Арендованы сейчас</th></tr></thead>
                    <tbody>
                    <?php foreach ($models as $model): ?>
                        <tr>
                            <td><?= e((string) $model['category_title']) ?></td>
                            <td class="number"><?= (int) $model['vehicles_count'] ?></td>
                            <td class="number"><?= (int) $model['rental_starts'] ?></td>
                            <td class="number"><?= (int) $model['returns_count'] ?></td>
                            <td class="number"><?= (int) $model['free_now'] ?></td>
                            <td class="number"><?= (int) $model['rented_now'] ?></td>
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
