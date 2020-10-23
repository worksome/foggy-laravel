<?php

namespace Worksome\FoggyLaravel;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Safe\Exceptions\JsonException;
use Worksome\Foggy\DumpProcess;

/**
 * Scrubs the database.
 */
class DatabaseDumpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:dump {--config= : path to a json config file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dump a scrubbed database.';

    /**
     * Execute the console command.
     *
     * @throws Exception
     */
    public function handle(): void
    {
        $configFile = $this->option('config') ?:  $this->getLaravel()->basePath('foggy.json');

        $process = new DumpProcess(
            DB::connection()->getDoctrineConnection(),
            $configFile,
            $this->getOutput()
        );

        $process->run();
    }
}
