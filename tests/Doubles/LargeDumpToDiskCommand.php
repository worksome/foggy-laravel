<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel\Tests\Doubles;

use Worksome\FoggyLaravel\DatabaseDumpToDiskCommand;

/** A dump that stays above S3's 5 MB multipart threshold even after gzip. */
final class LargeDumpToDiskCommand extends DatabaseDumpToDiskCommand
{
    private const string GENERATOR = 'for ($i = 0; $i < 200000; $i++) { echo hash("sha256", (string) $i, true); }';

    protected $name = 'db:dump-to-disk-large';

    public static function payload(): string
    {
        ob_start();
        eval(self::GENERATOR);

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    protected function dumpCommand(): array
    {
        return [PHP_BINARY, '-r', self::GENERATOR];
    }
}
