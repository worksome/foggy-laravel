<?php

declare(strict_types=1);

use Worksome\FoggyLaravel\DatabaseDumpCommand;

beforeEach(function () {
    file_put_contents(
        base_path('foggy.json'),
        <<<'JSON'
    {
        "database": {
            "*": {
                "withData": true
            }
        }
    }
    JSON
    );
});

afterEach(function () {
    if (file_exists($foggyConfig = base_path('foggy.json'))) {
        unlink($foggyConfig);
    }
});

it('can run the `db:dump` command', function () {
    $this
        ->artisan(DatabaseDumpCommand::class)
        ->expectsOutputToContain('SET NAMES utf8mb4 ;')
        ->assertOk();
});
