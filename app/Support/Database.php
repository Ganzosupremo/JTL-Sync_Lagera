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

    private static function safeCharset(string $charset): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $charset) === 1 ? $charset : 'utf8mb4';
    }

    private static function safeCollation(string $collation): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1 ? $collation : 'utf8mb4_unicode_ci';
    }
}
