<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel\Tests\Doubles;

use Worksome\FoggyLaravel\DatabaseDumpToDiskCommand;

/** A dump that stays above S3's 5 MB multipart threshold even after gzip, then ends with an optional trailer. */
final class LargeDumpToDiskCommand extends DatabaseDumpToDiskCommand
{
    private const string GENERATOR = 'for ($i = 0; $i < 200000; $i++) { echo hash("sha256", (string) $i, true); }';

    protected $name = 'db:dump-to-disk-large';

    public function __construct(private readonly string $trailer = '')
    {
        parent::__construct();
    }

    public function payload(): string
    {
        ob_start();
        eval(self::GENERATOR);

        return ob_get_clean() . $this->trailer;
    }

    /** @return list<string> */
    protected function dumpCommand(): array
    {
        return [PHP_BINARY, '-r', self::GENERATOR . ' echo ' . var_export($this->trailer, true) . ';'];
    }
}
