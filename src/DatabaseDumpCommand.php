<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Worksome\Foggy\DumpProcess;

use function Safe\fopen;

class DatabaseDumpCommand extends Command
{
    /** {@inheritdoc} */
    protected $signature = 'db:dump {--config= : The path to a JSON config file}
                                {--o|output= : The path to an output file}
                                {--connection= : The database connection to use}';

    /** {@inheritdoc} */
    protected $description = 'Dump a scrubbed database.';

    public function handle(): void
    {
        $configFile = $this->option('config') ?: $this->getLaravel()->basePath('foggy.json');

        $dumpOutput = $this->option('output')
            ? new StreamOutput(fopen($this->option('output'), 'w'))
            : $this->getOutput()->getOutput();

        $consoleOutput = match (true) {
            $this->option('output') && $this->option('quiet') => new ConsoleOutput(OutputInterface::VERBOSITY_QUIET),
            $this->option('output') => $this->getOutput()->getOutput(),
            default => null,
        };

        $process = new DumpProcess(
            DB::connection($this->option('connection'))->getDoctrineConnection(),
            $configFile,
            $dumpOutput,
            $consoleOutput
        );

        $process->run();
    }
}
