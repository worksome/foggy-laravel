<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel\Enums;

use Doctrine\DBAL\Driver;
use Worksome\FoggyLaravel\Concerns\ConnectsToDatabase;

enum SupportedDriver
{
    case MySQL;
    case PostgreSQL;
    case SQLite;
    case SqlServer;

    public function driverName(): string
    {
        return match ($this) {
            self::MySQL => 'pdo_mysql',
            self::PostgreSQL => 'pdo_pgsql',
            self::SQLite => 'pdo_sqlite',
            self::SqlServer => 'pdo_sqlsrv',
        };
    }

    public function driver(): Driver
    {
        return match ($this) {
            self::MySQL => new class() extends Driver\AbstractMySQLDriver {
                use ConnectsToDatabase;
            },
            self::PostgreSQL => new class() extends Driver\AbstractPostgreSQLDriver {
                use ConnectsToDatabase;
            },
            self::SQLite => new class() extends Driver\AbstractSQLiteDriver {
                use ConnectsToDatabase;
            },
            self::SqlServer => new class() extends Driver\AbstractSQLServerDriver {
                use ConnectsToDatabase;
            },
        };
    }
}
