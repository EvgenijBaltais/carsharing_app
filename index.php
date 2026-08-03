<?php

declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$dbHost = getenv('VORON_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('VORON_DB_PORT') ?: '3306';
$dbName = getenv('VORON_DB_NAME') ?: 'voron';
$dbUser = getenv('VORON_DB_USER') ?: 'root';
$dbPass = getenv('VORON_DB_PASS');
$dbPass = $dbPass === false ? '' : $dbPass;

$catalog = [];
$errorMessage = null;

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $rows = $pdo->query(
        'SELECT
            vt.id AS type_id,
            vt.external_id AS type_external_id,
            vt.name AS type_name,
            b.id AS brand_id,
            b.name AS brand_name,
            m.id AS model_id,
            m.name AS model_name
         FROM vehicle_types vt
         LEFT JOIN vehicle_type_brands vtb
            ON vtb.vehicle_type_id = vt.id AND vtb.is_active = 1
         LEFT JOIN brands b
            ON b.id = vtb.brand_id AND b.is_active = 1
         LEFT JOIN vehicle_type_models vtm
            ON vtm.vehicle_type_brand_id = vtb.id AND vtm.is_active = 1
         LEFT JOIN models m
            ON m.id = vtm.model_id AND m.is_active = 1
         WHERE vt.is_active = 1
         ORDER BY vt.id, b.name, m.name'
    )->fetchAll();

    foreach ($rows as $row) {
        $typeId = (int) $row['type_id'];
        if (!isset($catalog[$typeId])) {
            $catalog[$typeId] = [
                'id' => $typeId,
                'external_id' => (int) $row['type_external_id'],
                'name' => (string) $row['type_name'],
                'brands' => [],
            ];
        }

        if ($row['brand_id'] === null) {
            continue;
        }

        $brandId = (int) $row['brand_id'];
        if (!isset($catalog[$typeId]['brands'][$brandId])) {
            $catalog[$typeId]['brands'][$brandId] = [
                'id' => $brandId,
                'name' => (string) $row['brand_name'],
                'models' => [],
            ];
        }

        if ($row['model_id'] !== null) {
            $modelId = (int) $row['model_id'];
            $catalog[$typeId]['brands'][$brandId]['models'][$modelId] = [
                'id' => $modelId,
                'name' => (string) $row['model_name'],
            ];
        }
    }
} catch (Throwable $exception) {
    error_log('Voron catalog error: ' . $exception->getMessage());
    $errorMessage = 'Не удалось загрузить каталог. Проверьте, что OpenServer и MySQL запущены.';
}

$typeCount = count($catalog);
$typeBrandCount = 0;
$typeModelCount = 0;
foreach ($catalog as $type) {
    $typeBrandCount += count($type['brands']);
    foreach ($type['brands'] as $brand) {
        $typeModelCount += count($brand['models']);
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Каталог транспорта Voron</title>
    <style>
        :root {
            color-scheme: light;
            --page: #f4f6f8;
            --surface: #ffffff;
            --surface-soft: #f8fafb;
            --text: #182026;
            --muted: #65727d;
            --line: #dfe5e9;
            --accent: #15191d;
            --accent-soft: #e9edf0;
            --danger: #a43636;
            --danger-bg: #fff0f0;
            --radius: 16px;
            --shadow: 0 14px 36px rgba(27, 37, 44, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--page);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        button,
        summary {
            font: inherit;
        }

        .page {
            width: min(980px, calc(100% - 32px));
            margin: 0 auto;
            padding: 56px 0 72px;
        }

        .header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 28px;
        }

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
            font-size: clamp(32px, 5vw, 52px);
            line-height: 1.05;
            letter-spacing: -0.045em;
        }

        .intro {
            max-width: 580px;
            margin: 14px 0 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.6;
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: var(--surface);
            color: var(--text);
            padding: 9px 14px;
            cursor: pointer;
            text-decoration: none;
            transition: border-color 150ms ease, background 150ms ease;
        }

        .button:hover {
            border-color: #aeb8bf;
            background: var(--surface-soft);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat {
            padding: 18px 20px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.7);
        }

        .stat-value {
            display: block;
            font-size: 26px;
            font-weight: 750;
            line-height: 1;
        }

        .stat-label {
            display: block;
            margin-top: 7px;
            color: var(--muted);
            font-size: 13px;
        }

        .catalog {
            display: grid;
            gap: 12px;
        }

        details {
            overflow: hidden;
        }

        summary {
            list-style: none;
            cursor: pointer;
            user-select: none;
        }

        summary::-webkit-details-marker {
            display: none;
        }

        .vehicle-type {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow);
        }

        .type-summary,
        .brand-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .type-summary {
            padding: 20px 22px;
        }

        .summary-main {
            min-width: 0;
        }

        .summary-title {
            display: block;
            font-size: 18px;
            font-weight: 720;
        }

        .summary-meta {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 13px;
        }

        .chevron {
            flex: 0 0 auto;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--accent-soft);
            position: relative;
        }

        .chevron::before,
        .chevron::after {
            content: "";
            position: absolute;
            top: 15px;
            width: 8px;
            height: 2px;
            border-radius: 2px;
            background: var(--accent);
            transition: transform 160ms ease;
        }

        .chevron::before {
            left: 9px;
            transform: rotate(45deg);
        }

        .chevron::after {
            right: 9px;
            transform: rotate(-45deg);
        }

        details[open] > summary .chevron::before {
            transform: rotate(-45deg);
        }

        details[open] > summary .chevron::after {
            transform: rotate(45deg);
        }

        .brands {
            padding: 0 12px 12px;
        }

        .brand {
            border-top: 1px solid var(--line);
        }

        .brand-summary {
            padding: 16px 10px;
        }

        .brand-summary .summary-title {
            font-size: 15px;
        }

        .brand-summary .chevron {
            width: 26px;
            height: 26px;
            background: transparent;
        }

        .brand-summary .chevron::before,
        .brand-summary .chevron::after {
            top: 12px;
            width: 7px;
        }

        .brand-summary .chevron::before {
            left: 7px;
        }

        .brand-summary .chevron::after {
            right: 7px;
        }

        .models {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 8px;
            margin: 0;
            padding: 0 10px 16px;
            list-style: none;
        }

        .model {
            min-width: 0;
            padding: 11px 12px;
            border-radius: 10px;
            background: var(--surface-soft);
            color: #34414a;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .empty,
        .error {
            padding: 24px;
            border-radius: var(--radius);
            text-align: center;
        }

        .empty {
            border: 1px dashed var(--line);
            color: var(--muted);
            background: var(--surface);
        }

        .error {
            border: 1px solid #efbcbc;
            color: var(--danger);
            background: var(--danger-bg);
        }

        @media (max-width: 700px) {
            .page {
                width: min(100% - 20px, 980px);
                padding-top: 32px;
            }

            .header {
                display: block;
            }

            .controls {
                justify-content: flex-start;
                margin-top: 20px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .type-summary {
                padding: 17px 16px;
            }

            .models {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="header">
        <div>
            <p class="eyebrow">Voron · каталог</p>
            <h1>Транспорт</h1>
            <p class="intro">Выберите вид транспорта, затем раскройте марку, чтобы посмотреть доступные модели.</p>
        </div>
        <?php if ($errorMessage === null && $catalog !== []): ?>
            <div class="controls" aria-label="Управление каталогом">
                <a class="button" href="diagnostic.php">Диагностика статусов</a>
                <a class="button" href="collector.php">Собрать снимок</a>
                <a class="button" href="analytics.php">Аналитика аренды</a>
                <button class="button" type="button" data-action="expand">Раскрыть всё</button>
                <button class="button" type="button" data-action="collapse">Свернуть всё</button>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($errorMessage !== null): ?>
        <div class="error" role="alert"><?= e($errorMessage) ?></div>
    <?php elseif ($catalog === []): ?>
        <div class="empty">Каталог пока пуст.</div>
    <?php else: ?>
        <section class="stats" aria-label="Статистика каталога">
            <div class="stat">
                <span class="stat-value"><?= $typeCount ?></span>
                <span class="stat-label">видов транспорта</span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= $typeBrandCount ?></span>
                <span class="stat-label">связок с марками</span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= $typeModelCount ?></span>
                <span class="stat-label">моделей в категориях</span>
            </div>
        </section>

        <section class="catalog" aria-label="Каталог транспорта">
            <?php foreach ($catalog as $type): ?>
                <?php
                $brandCount = count($type['brands']);
                $modelCount = 0;
                foreach ($type['brands'] as $brand) {
                    $modelCount += count($brand['models']);
                }
                ?>
                <details class="vehicle-type">
                    <summary class="type-summary">
                        <span class="summary-main">
                            <span class="summary-title"><?= e($type['name']) ?></span>
                            <span class="summary-meta"><?= $brandCount ?> марок · <?= $modelCount ?> моделей</span>
                        </span>
                        <span class="chevron" aria-hidden="true"></span>
                    </summary>

                    <div class="brands">
                        <?php foreach ($type['brands'] as $brand): ?>
                            <details class="brand">
                                <summary class="brand-summary">
                                    <span class="summary-main">
                                        <span class="summary-title"><?= e($brand['name']) ?></span>
                                        <span class="summary-meta"><?= count($brand['models']) ?> моделей</span>
                                    </span>
                                    <span class="chevron" aria-hidden="true"></span>
                                </summary>

                                <?php if ($brand['models'] === []): ?>
                                    <p class="empty">Для этой марки модели пока не добавлены.</p>
                                <?php else: ?>
                                    <ul class="models">
                                        <?php foreach ($brand['models'] as $model): ?>
                                            <li class="model"><?= e($model['name']) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<script>
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-action]');
        if (!button) return;

        const shouldOpen = button.dataset.action === 'expand';
        document.querySelectorAll('.catalog details').forEach(function (item) {
            item.open = shouldOpen;
        });
    });
</script>
</body>
</html>
