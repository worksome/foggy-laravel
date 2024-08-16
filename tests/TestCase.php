<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Worksome\FoggyLaravel\FoggyServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FoggyServiceProvider::class,
        ];
    }
}
