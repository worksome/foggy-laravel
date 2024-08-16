<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\StreamOutput;
use Worksome\Foggy\DumpProcess;

use function Safe\fopen;

class DatabaseDumpCommand extends Command
{
    /** {@inheritdoc} */
    protected $signature = 'db:dump {--config= : path to a json config file}
                                {--o|output= : path to an output file}';

    /** {@inheritdoc} */
    protected $description = 'Dump a scrubbed database.';

    public function handle(): void
    {
        $configFile = $this->option('config') ?: $this->getLaravel()->basePath('foggy.json');

        $dumpOutput = $this->option('output')
            ? new StreamOutput(fopen($this->option('output'), 'w'))
            : $this->getOutput()->getOutput();

        $consoleOutput = $this->option('output') ? $this->getOutput()->getOutput() : null;

        $process = new DumpProcess(
            DB::connection()->getDoctrineConnection(),
            $configFile,
            $dumpOutput,
            $consoleOutput
        );

        $process->run();
    }
}
