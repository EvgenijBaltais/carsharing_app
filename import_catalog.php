<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const DEFAULT_CATALOG_PATH = 'C:\\Voron\\catalog.json';

function repairText(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
        return $value;
    }

    // catalog.json был сохранён после ошибочного декодирования UTF-8 как Windows-1251.
    $fixed = mb_convert_encoding($value, 'Windows-1251', 'UTF-8');
    if ($fixed !== '' && mb_check_encoding($fixed, 'UTF-8')) {
        return trim($fixed);
    }

    return $value;
}

function normalizeName(string $value): string
{
    $value = repairText($value);
    $value = str_replace(["\u{00A0}", "\u{2060}"], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

    return mb_strtolower($value, 'UTF-8');
}

function childItems(array $item): array
{
    return isset($item['children']) && is_array($item['children'])
        ? $item['children']
        : [];
}

function requiredId(array $item, string $level): int
{
    if (!isset($item['id']) || !is_numeric($item['id'])) {
        throw new RuntimeException("Отсутствует ID уровня: {$level}");
    }

    return (int) $item['id'];
}

function requiredTitle(array $item, string $level): string
{
    $title = repairText(isset($item['title']) ? (string) $item['title'] : '');
    if ($title === '') {
        throw new RuntimeException("Отсутствует название уровня: {$level}");
    }

    return $title;
}

$catalogPath = $argv[1] ?? DEFAULT_CATALOG_PATH;
if (!is_file($catalogPath) || !is_readable($catalogPath)) {
    fwrite(STDERR, "Файл каталога не найден или недоступен: {$catalogPath}\n");
    exit(1);
}

$raw = file_get_contents($catalogPath);
if ($raw === false) {
    fwrite(STDERR, "Не удалось прочитать каталог: {$catalogPath}\n");
    exit(1);
}

try {
    $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "Некорректный JSON: {$exception->getMessage()}\n");
    exit(1);
}

if (($catalog['success'] ?? false) !== true) {
    fwrite(STDERR, "API-каталог не содержит success=true.\n");
    exit(1);
}

$categories = $catalog['result']['categories'] ?? null;
if (!is_array($categories) || $categories === []) {
    fwrite(STDERR, "В каталоге отсутствует массив result.categories.\n");
    exit(1);
}

$dbHost = getenv('VORON_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('VORON_DB_PORT') ?: '3306';
$dbName = getenv('VORON_DB_NAME') ?: 'carsharing_app';
$dbUser = getenv('VORON_DB_USER') ?: 'carsharing_app';
$dbPass = getenv('VORON_DB_PASS');
$dbPass = $dbPass === false ? '' : $dbPass;

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

    $pdo->beginTransaction();

    // Записи, отсутствующие в новом полном каталоге, остаются для истории, но становятся неактивными.
    $pdo->exec('UPDATE vehicle_type_models SET is_active = 0');
    $pdo->exec('UPDATE vehicle_type_brands SET is_active = 0');
    $pdo->exec('UPDATE vehicle_types SET is_active = 0');
    $pdo->exec('UPDATE models SET is_active = 0');
    $pdo->exec('UPDATE brands SET is_active = 0');

    $upsertType = $pdo->prepare(
        'INSERT INTO vehicle_types
            (external_id, name, normalized_name, slug, is_active)
         VALUES (:external_id, :name, :normalized_name, :slug, 1)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            name = VALUES(name),
            normalized_name = VALUES(normalized_name),
            slug = VALUES(slug),
            is_active = 1'
    );

    $upsertBrand = $pdo->prepare(
        'INSERT INTO brands (name, normalized_name, is_active)
         VALUES (:name, :normalized_name, 1)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            name = VALUES(name),
            is_active = 1'
    );

    $upsertTypeBrand = $pdo->prepare(
        'INSERT INTO vehicle_type_brands
            (vehicle_type_id, brand_id, external_id, source_mode, is_active)
         VALUES (:vehicle_type_id, :brand_id, :external_id, :source_mode, 1)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            vehicle_type_id = VALUES(vehicle_type_id),
            brand_id = VALUES(brand_id),
            external_id = VALUES(external_id),
            source_mode = VALUES(source_mode),
            is_active = 1'
    );

    $upsertModel = $pdo->prepare(
        'INSERT INTO models (brand_id, name, normalized_name, is_active)
         VALUES (:brand_id, :name, :normalized_name, 1)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            name = VALUES(name),
            is_active = 1'
    );

    $upsertTypeModel = $pdo->prepare(
        'INSERT INTO vehicle_type_models
            (vehicle_type_brand_id, model_id, external_id, source_mode, is_active)
         VALUES (:vehicle_type_brand_id, :model_id, :external_id, :source_mode, 1)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            vehicle_type_brand_id = VALUES(vehicle_type_brand_id),
            model_id = VALUES(model_id),
            external_id = VALUES(external_id),
            source_mode = VALUES(source_mode),
            is_active = 1'
    );

    $processed = [
        'types' => 0,
        'type_brands' => 0,
        'type_models' => 0,
    ];

    foreach ($categories as $type) {
        if (!is_array($type)) {
            continue;
        }

        $typeExternalId = requiredId($type, 'вид транспорта');
        $typeName = requiredTitle($type, 'вид транспорта');
        $upsertType->execute([
            'external_id' => $typeExternalId,
            'name' => $typeName,
            'normalized_name' => normalizeName($typeName),
            'slug' => 'type-' . $typeExternalId,
        ]);
        $typeId = (int) $pdo->lastInsertId();
        $processed['types']++;

        foreach (childItems($type) as $brand) {
            if (!is_array($brand)) {
                continue;
            }

            $brandExternalId = requiredId($brand, "марка для {$typeName}");
            $brandName = requiredTitle($brand, "марка для {$typeName}");
            $upsertBrand->execute([
                'name' => $brandName,
                'normalized_name' => normalizeName($brandName),
            ]);
            $brandId = (int) $pdo->lastInsertId();

            $upsertTypeBrand->execute([
                'vehicle_type_id' => $typeId,
                'brand_id' => $brandId,
                'external_id' => $brandExternalId,
                'source_mode' => isset($brand['mode']) ? (string) $brand['mode'] : null,
            ]);
            $typeBrandId = (int) $pdo->lastInsertId();
            $processed['type_brands']++;

            foreach (childItems($brand) as $model) {
                if (!is_array($model)) {
                    continue;
                }

                $modelExternalId = requiredId($model, "модель {$brandName}");
                $modelName = requiredTitle($model, "модель {$brandName}");
                $upsertModel->execute([
                    'brand_id' => $brandId,
                    'name' => $modelName,
                    'normalized_name' => normalizeName($modelName),
                ]);
                $modelId = (int) $pdo->lastInsertId();

                $upsertTypeModel->execute([
                    'vehicle_type_brand_id' => $typeBrandId,
                    'model_id' => $modelId,
                    'external_id' => $modelExternalId,
                    'source_mode' => isset($model['mode']) ? (string) $model['mode'] : null,
                ]);
                $processed['type_models']++;
            }
        }
    }

    $pdo->commit();

    $databaseCounts = [];
    foreach (['vehicle_types', 'brands', 'vehicle_type_brands', 'models', 'vehicle_type_models'] as $table) {
        $databaseCounts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE is_active = 1")->fetchColumn();
    }

    echo "Каталог успешно импортирован.\n";
    echo "Версия каталога: " . ($catalog['result']['version'] ?? 'не указана') . "\n";
    echo "Обработано видов: {$processed['types']}\n";
    echo "Обработано связок вид-марка: {$processed['type_brands']}\n";
    echo "Обработано связок вид-марка-модель: {$processed['type_models']}\n";
    echo "Активных уникальных марок: {$databaseCounts['brands']}\n";
    echo "Активных уникальных моделей: {$databaseCounts['models']}\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Ошибка импорта: {$exception->getMessage()}\n");
    exit(1);
}
