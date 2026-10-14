<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Worksome\FoggyLaravel\DatabaseDumpCommand;
use Worksome\FoggyLaravel\DatabaseDumpToDiskCommand;
use Worksome\FoggyLaravel\FoggyServiceProvider;

it('registers the `db:dump` command', function () {
    $provider = new FoggyServiceProvider($this->app);

    $provider->register();

    $this->assertInstanceOf(DatabaseDumpCommand::class, $this->app[DatabaseDumpCommand::class]);
});

it('registers the `db:dump-to-disk` command', function () {
    expect(Artisan::all())->toHaveKey('db:dump-to-disk')
        ->and(Artisan::all()['db:dump-to-disk'])->toBeInstanceOf(DatabaseDumpToDiskCommand::class);
});
