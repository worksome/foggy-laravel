<?php

declare(strict_types=1);

use Worksome\FoggyLaravel\DatabaseDumpCommand;
use Worksome\FoggyLaravel\FoggyServiceProvider;

it('registers the `db:dump` command', function () {
    $provider = new FoggyServiceProvider($this->app);

    $provider->register();

    $this->assertInstanceOf(DatabaseDumpCommand::class, $this->app[DatabaseDumpCommand::class]);
});
