<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

function migrationDatabase(): PDO
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

function migrationTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);

    return (int) $statement->fetchColumn() > 0;
}

function migrationColumnExists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $statement->execute(['table_name' => $table, 'column_name' => $column]);

    return (int) $statement->fetchColumn() > 0;
}

function migrationIndexExists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
    );
    $statement->execute(['table_name' => $table, 'index_name' => $index]);

    return (int) $statement->fetchColumn() > 0;
}

function migrationColumnsExist(PDO $pdo, string $table, array $columns): bool
{
    foreach ($columns as $column) {
        if (!migrationColumnExists($pdo, $table, $column)) {
            return false;
        }
    }

    return true;
}

function executeMigrationFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Не удалось прочитать файл миграции: ' . $path);
    }

    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = preg_split('/;\s*(?:\r\n|\r|\n|$)/', (string) $sql);
    if ($statements === false) {
        throw new RuntimeException('Не удалось разобрать файл миграции: ' . $path);
    }

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

$migrations = [
    '001_collector' => [
        'file' => __DIR__ . '/migration_collector.sql',
        'check' => function (PDO $pdo): bool {
            return migrationColumnsExist($pdo, 'collector_runs', ['free_received', 'busy_received', 'latitude', 'longitude'])
                && (migrationColumnExists($pdo, 'collector_runs', 'conflicts_count') || migrationColumnExists($pdo, 'collector_runs', 'overlap_count'))
                && migrationColumnsExist($pdo, 'vehicles', ['category_title', 'base_category_title', 'type_external_id']);
        },
    ],
    '002_status_tracking' => [
        'file' => __DIR__ . '/migration_status_tracking.sql',
        'check' => function (PDO $pdo): bool {
            return migrationColumnsExist($pdo, 'vehicles', ['state_info', 'state_time_seconds', 'state_started_at', 'status_source_endpoint'])
                && migrationColumnsExist($pdo, 'vehicle_status_history', ['state_info', 'state_time_seconds', 'state_started_at', 'source_endpoint']);
        },
    ],
    '003_events' => [
        'file' => __DIR__ . '/migration_events.sql',
        'check' => function (PDO $pdo): bool {
            return migrationTableExists($pdo, 'vehicle_status_events')
                && migrationColumnExists($pdo, 'collector_runs', 'overlap_count')
                && migrationIndexExists($pdo, 'vehicle_status_history', 'idx_vehicle_status_vehicle_observed');
        },
    ],
    '004_new_vehicle_badge' => [
        'file' => __DIR__ . '/migration_new_vehicle_badge.sql',
        'check' => function (PDO $pdo): bool {
            return migrationColumnExists($pdo, 'vehicles', 'new_until');
        },
    ],
    '005_update_intervals' => [
        'file' => __DIR__ . '/migration_update_intervals.sql',
        'check' => function (PDO $pdo): bool {
            if (!migrationTableExists($pdo, 'update_intervals')) {
                return false;
            }

            return (int) $pdo->query(
                "SELECT COUNT(*) FROM update_intervals
                 WHERE code IN ('vehicle_statuses', 'vehicle_catalog')"
            )->fetchColumn() === 2;
        },
    ],
];

$statusOnly = in_array('--status', $argv, true);
$pdo = migrationDatabase();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$loadApplied = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = :migration');
$recordApplied = $pdo->prepare(
    'INSERT INTO schema_migrations (migration, checksum)
     VALUES (:migration, :checksum)'
);

try {
    foreach ($migrations as $name => $migration) {
        $path = (string) $migration['file'];
        if (!is_file($path)) {
            throw new RuntimeException('Отсутствует файл миграции: ' . $path);
        }

        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException('Не удалось вычислить контрольную сумму: ' . $path);
        }

        $loadApplied->execute(['migration' => $name]);
        $appliedChecksum = $loadApplied->fetchColumn();
        if ($appliedChecksum !== false) {
            if (!hash_equals((string) $appliedChecksum, $checksum)) {
                throw new RuntimeException("Файл уже применённой миграции {$name} был изменён.");
            }
            echo "[OK] {$name} уже применена.\n";
            continue;
        }

        $alreadyPresent = (bool) $migration['check']($pdo);
        if ($statusOnly) {
            echo $alreadyPresent
                ? "[OK] {$name}: изменения уже присутствуют, но ещё не записаны в журнал.\n"
                : "[--] {$name}: ожидает применения.\n";
            continue;
        }

        if (!$alreadyPresent) {
            echo "[>>] Применяется {$name}...\n";
            executeMigrationFile($pdo, $path);
            if (!(bool) $migration['check']($pdo)) {
                throw new RuntimeException("Проверка миграции {$name} после выполнения не пройдена.");
            }
        } else {
            echo "[==] {$name}: изменения уже присутствуют, добавляю в журнал.\n";
        }

        $recordApplied->execute(['migration' => $name, 'checksum' => $checksum]);
        echo "[OK] {$name}.\n";
    }

    echo $statusOnly ? "Проверка завершена.\n" : "База данных обновлена.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");
    exit(1);
}
