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
        return ['sh', '-c', 'echo "SET NAMES utf8mb4 ;"; echo "something broke" >&2; exit 3'];
    }
}
