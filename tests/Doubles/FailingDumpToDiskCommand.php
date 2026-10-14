<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel\Tests\Doubles;

use Worksome\FoggyLaravel\DatabaseDumpToDiskCommand;

/** A child process that writes to stderr and exits non-zero partway through. */
final class FailingDumpToDiskCommand extends DatabaseDumpToDiskCommand
{
    protected $name = 'db:dump-to-disk-failing';

    /** @return list<string> */
    protected function dumpCommand(): array
    {
        return [PHP_BINARY, '-r', 'echo "SET NAMES utf8mb4 ;\n"; fwrite(STDERR, "something broke\n"); exit(3);'];
    }
}
