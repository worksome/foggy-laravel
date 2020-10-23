<?php

namespace Worksome\FoggyLaravel;

use Illuminate\Support\ServiceProvider;

class FoggyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DatabaseDumpCommand::class
            ]);
        }
    }
}