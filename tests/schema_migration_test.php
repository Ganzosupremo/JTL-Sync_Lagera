<?php

declare(strict_types=1);

require __DIR__ . '/support/bootstrap.php';

use App\Support\Database;

$db = requireTestDatabase();

function forgetMigrationInThisProcess(): void
{
    (new ReflectionProperty(Database::class, 'migrated'))->setValue(null, false);
}

function storedSchemaVersion(mysqli $db): ?string
{
    $row = $db->query('SELECT version FROM app_schema_version WHERE id = 1')->fetch_assoc();

    return is_array($row) ? (string) $row['version'] : null;
}

function tableExists(mysqli $db, string $table): bool
{
    return (bool) $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")->fetch_row();
}

// The bootstrap truncated every table, the version row included, so this runs the full migration.
forgetMigrationInThisProcess();
Database::migrate();
assertSame(Database::schemaVersion(), storedSchemaVersion($db), 'La migracion debe guardar la version del esquema.');

// 1. With the current version stored, migrate() skips the migration entirely.
$db->query('DROP TABLE sync_logs');
forgetMigrationInThisProcess();
Database::migrate();
assertTrue(!tableExists($db, 'sync_logs'), 'Con la version al dia no debe repetirse la migracion.');

// 2. force recreates what is missing.
Database::migrate(force: true);
assertTrue(tableExists($db, 'sync_logs'), 'migrate(force: true) debe recrear las tablas que falten.');

// 3. A changed schema (other stored version) runs the migration again.
$db->query('DROP TABLE sync_logs');
$db->query("UPDATE app_schema_version SET version = 'old' WHERE id = 1");
forgetMigrationInThisProcess();
Database::migrate();
assertTrue(tableExists($db, 'sync_logs'), 'Una version distinta debe volver a migrar.');
assertSame(Database::schemaVersion(), storedSchemaVersion($db), 'Tras migrar debe guardarse la version nueva.');

// 4. The sync_logs index used for pruning exists.
assertTrue(
    (bool) $db->query("SHOW INDEX FROM sync_logs WHERE Key_name = 'sync_logs_created_at_index'")->fetch_row(),
    'sync_logs debe tener indice en created_at.'
);

echo "schema_migration_test: OK\n";
