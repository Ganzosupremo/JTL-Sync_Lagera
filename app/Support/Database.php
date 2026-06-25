<?php

declare(strict_types=1);

namespace App\Support;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;

final class Database
{
    private static ?mysqli $connection = null;

    public static function connection(): mysqli
    {
        if (self::$connection instanceof mysqli) {
            return self::$connection;
        }

        $driver = (string) Config::get('database.driver', 'mysql');

        if ($driver !== 'mysql') {
            throw new RuntimeException("Unsupported database driver: {$driver}. This app is configured for MySQL.");
        }

        self::$connection = self::mysqlConnection();

        return self::$connection;
    }

    public static function migrate(): void
    {
        $db = self::connection();

        $db->query(
            "CREATE TABLE IF NOT EXISTS order_mappings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                jtl_order_id VARCHAR(100) NOT NULL,
                jtl_order_number VARCHAR(100),
                packiyo_order_id VARCHAR(100) NOT NULL,
                packiyo_order_number VARCHAR(100),
                synced_at DATETIME NOT NULL,
                UNIQUE KEY order_mappings_jtl_order_id_unique (jtl_order_id)
            )"
        );

        $db->query(
            "CREATE TABLE IF NOT EXISTS sync_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL,
                level VARCHAR(20),
                source VARCHAR(50),
                message TEXT
            )"
        );

        $db->query(
            "CREATE TABLE IF NOT EXISTS jtl_api_credentials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                registration_request_id VARCHAR(120) NULL,
                authentication_endpoint VARCHAR(255) NULL,
                api_version VARCHAR(20) NULL,
                api_key TEXT NULL,
                granted_scopes TEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                requested_at DATETIME NULL,
                approved_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY jtl_api_credentials_request_unique (registration_request_id)
            )"
        );

        self::ensureJtlCredentialColumns($db);

        $db->query(
            "CREATE TABLE IF NOT EXISTS packiyo_customer_mappings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                match_type VARCHAR(50) NOT NULL,
                match_value VARCHAR(255) NOT NULL,
                packiyo_customer_id VARCHAR(100) NOT NULL,
                packiyo_customer_name VARCHAR(255) NULL,
                priority INT NOT NULL DEFAULT 100,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY packiyo_customer_mappings_match_unique (match_type, match_value)
            )"
        );

        $db->query(
            "CREATE TABLE IF NOT EXISTS packiyo_customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                packiyo_customer_id VARCHAR(100) NOT NULL,
                name VARCHAR(255) NULL,
                email VARCHAR(255) NULL,
                company_name VARCHAR(255) NULL,
                raw_attributes JSON NULL,
                packiyo_created_at DATETIME NULL,
                packiyo_updated_at DATETIME NULL,
                synced_at DATETIME NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY packiyo_customers_customer_id_unique (packiyo_customer_id),
                KEY packiyo_customers_active_index (active),
                KEY packiyo_customers_packiyo_updated_at_index (packiyo_updated_at)
            )"
        );

        $db->query(
            "CREATE TABLE IF NOT EXISTS app_sync_states (
                sync_key VARCHAR(100) PRIMARY KEY,
                last_synced_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_message TEXT NULL,
                updated_at DATETIME NOT NULL
            )"
        );

        $db->query(
            "CREATE TABLE IF NOT EXISTS jtl_order_sources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_type VARCHAR(50) NOT NULL,
                source_value VARCHAR(255) NOT NULL,
                source_path VARCHAR(255) NULL,
                order_count INT NOT NULL DEFAULT 0,
                sample_order_id VARCHAR(100) NULL,
                sample_order_number VARCHAR(100) NULL,
                last_seen_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY jtl_order_sources_type_value_unique (source_type, source_value),
                KEY jtl_order_sources_type_index (source_type)
            )"
        );

        self::ensureJtlOrderSourceColumns($db);
    }

    private static function mysqlConnection(): mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $host = (string) Config::get('database.mysql.host', '127.0.0.1');
        $port = (int) Config::get('database.mysql.port', '3306');
        $database = (string) Config::get('database.mysql.database', 'jtlsync');
        $charset = self::safeCharset((string) Config::get('database.mysql.charset', 'utf8mb4'));
        $collation = self::safeCollation((string) Config::get('database.mysql.collation', 'utf8mb4_unicode_ci'));
        $username = (string) Config::get('database.mysql.username', 'root');
        $password = (string) Config::get('database.mysql.password', '');

        if ($database === '') {
            throw new RuntimeException('DB_DATABASE is required for MySQL.');
        }

        try {
            $connection = new mysqli($host, $username, $password, $database, $port);
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() !== 1049) {
                throw new RuntimeException('Could not connect to MySQL: ' . $exception->getMessage(), 0, $exception);
            }

            $connection = new mysqli($host, $username, $password, '', $port);
            $connection->set_charset($charset);
            $connection->query(
                sprintf(
                    'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s',
                    self::identifier($database),
                    $charset,
                    $collation
                )
            );
            $connection->select_db($database);
        }

        $connection->set_charset($charset);

        return $connection;
    }

    private static function identifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    private static function ensureJtlCredentialColumns(mysqli $db): void
    {
        $columns = [
            'registration_request_id' => 'registration_request_id VARCHAR(120) NULL AFTER id',
            'authentication_endpoint' => 'authentication_endpoint VARCHAR(255) NULL AFTER registration_request_id',
            'api_version' => 'api_version VARCHAR(20) NULL AFTER authentication_endpoint',
            'api_key' => 'api_key TEXT NULL AFTER api_version',
            'granted_scopes' => 'granted_scopes TEXT NULL AFTER api_key',
            'status' => "status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER granted_scopes",
            'requested_at' => 'requested_at DATETIME NULL AFTER status',
            'approved_at' => 'approved_at DATETIME NULL AFTER requested_at',
            'created_at' => 'created_at DATETIME NULL AFTER approved_at',
            'updated_at' => 'updated_at DATETIME NULL AFTER created_at',
        ];

        foreach ($columns as $column => $definition) {
            if (self::columnExists($db, 'jtl_api_credentials', $column)) {
                continue;
            }

            self::addColumnIfMissing($db, 'jtl_api_credentials', $column, $definition);
        }

        if (!self::indexExists($db, 'jtl_api_credentials_request_unique')) {
            try {
                $db->query(
                    'ALTER TABLE jtl_api_credentials
                    ADD UNIQUE KEY jtl_api_credentials_request_unique (registration_request_id)'
                );
            } catch (mysqli_sql_exception $exception) {
                if ((int) $exception->getCode() !== 1061) {
                    throw $exception;
                }
            }
        }
    }

    private static function ensureJtlOrderSourceColumns(mysqli $db): void
    {
        self::addColumnIfMissing($db, 'jtl_order_sources', 'source_path', 'source_path VARCHAR(255) NULL AFTER source_value');
    }

    private static function addColumnIfMissing(mysqli $db, string $table, string $column, string $definition): void
    {
        if (self::columnExists($db, $table, $column)) {
            return;
        }

        try {
            $db->query('ALTER TABLE ' . self::identifier($table) . ' ADD COLUMN ' . $definition);
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() !== 1060) {
                throw $exception;
            }
        }
    }

    private static function columnExists(mysqli $db, string $table, string $column): bool
    {
        $statement = $db->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1'
        );
        $statement->bind_param('ss', $table, $column);
        $statement->execute();

        return (bool) $statement->get_result()->fetch_row();
    }

    private static function indexExists(mysqli $db, string $index): bool
    {
        $statement = $db->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
            LIMIT 1'
        );
        $table = 'jtl_api_credentials';
        $statement->bind_param('ss', $table, $index);
        $statement->execute();

        return (bool) $statement->get_result()->fetch_row();
    }

    private static function safeCharset(string $charset): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $charset) === 1 ? $charset : 'utf8mb4';
    }

    private static function safeCollation(string $collation): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1 ? $collation : 'utf8mb4_unicode_ci';
    }
}
