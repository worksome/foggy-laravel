<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel\Tests\Doubles;

use Worksome\FoggyLaravel\DatabaseDumpToDiskCommand;

/**
 * Swaps the child process for a canned payload, so the streaming, gzip, upload
 * and pointer behaviour can be exercised without a bootable `artisan`.
 */
final class StubDumpToDiskCommand extends DatabaseDumpToDiskCommand
{
    public const PAYLOAD = "SET NAMES utf8mb4 ;\nINSERT INTO `notes` VALUES ('mail_12@example.org');\n";

    protected $name = 'db:dump-to-disk-stub';

    /** @return list<string> */
    protected function dumpCommand(): array
    {
        return [PHP_BINARY, '-r', 'echo ' . var_export(self::PAYLOAD, true) . ';'];
    }
}
