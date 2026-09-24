<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel\Tests\Doubles;

use Worksome\FoggyLaravel\DatabaseDumpToDiskCommand;

/** A dump carrying a byte sequence that is not valid UTF-8, as a BLOB column would. */
final class BinaryDumpToDiskCommand extends DatabaseDumpToDiskCommand
{
    protected $name = 'db:dump-to-disk-binary';

    /** @return list<string> */
    protected function dumpCommand(): array
    {
        return [PHP_BINARY, '-r', 'echo "SET NAMES utf8mb4 ;\nINSERT INTO `blobs` VALUES (\'\xC3\x28\');\n";'];
    }
}
