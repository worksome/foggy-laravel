<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

use function Safe\tempnam;
use function Safe\unlink;

/**
 * Streams a scrubbed dump to a filesystem disk, gzipped.
 *
 * Wraps `db:dump` rather than reimplementing it: Foggy still does all the
 * scrubbing. Nothing is written to local disk on the way, so this works in a
 * container with no spare space — which matters once a dump is tens of gigabytes.
 */
class DatabaseDumpToDiskCommand extends Command
{
    /** {@inheritdoc} */
    protected $signature = 'db:dump-to-disk
                            {--disk= : The filesystem disk to upload to}
                            {--prefix=dumps : The key prefix within the disk}
                            {--name=dump : Basename for the object, before the timestamp}
                            {--connection= : The database connection to read from}
                            {--config= : Path to a Foggy JSON config}';

    /** {@inheritdoc} */
    protected $description = 'Dump a scrubbed database to a filesystem disk, gzipped.';

    public function handle(FilesystemFactory $filesystems): int
    {
        $diskName = $this->option('disk');
        $disk = $filesystems->disk(is_string($diskName) ? $diskName : null);
        $prefix = trim((string) $this->option('prefix'), '/');

        /**
         * Timestamped and salted. A re-run must not reuse the key of an object
         * `latest` already points at: the retry's own cleanup would delete the
         * good dump. Seconds are not enough, because a retry usually lands in
         * the same one.
         */
        $key = $this->join($prefix, sprintf(
            '%s-%s-%s.sql.gz',
            $this->option('name'),
            now()->format('Y-m-d-His'),
            bin2hex(random_bytes(3)),
        ));

        /** @var array<string, string>|null $patterns */
        $patterns = config('foggy.scan_patterns');
        UnscrubbedDataFilter::register($patterns);

        $this->info("Dumping to {$key}...");
        $startedAt = microtime(true);

        // Foggy writes SQL to stdout and its progress bar to stderr, so the two
        // never mix. Progress goes to a file we only read if the dump fails.
        $progressLog = tempnam(sys_get_temp_dir(), 'foggy-dump-');
        $process = null;
        $pipes = [];

        try {
            $process = proc_open(
                $this->dumpCommand(),
                [1 => ['pipe', 'w'], 2 => ['file', $progressLog, 'w']],
                $pipes,
            );

            if ($process === false) {
                throw new RuntimeException('Unable to start the db:dump process.');
            }

            // Order matters: the scan has to see plaintext, so it goes on before
            // deflate. Window 31 asks zlib for a gzip container rather than raw.
            stream_filter_append($pipes[1], UnscrubbedDataFilter::NAME, STREAM_FILTER_READ);
            stream_filter_append($pipes[1], 'zlib.deflate', STREAM_FILTER_READ, ['level' => 6, 'window' => 31]);

            // Returns false rather than throwing unless the disk sets
            // 'throw' => true, so both outcomes are handled: the check below and
            // the catch. Ignoring either would publish a pointer to an object
            // that was never stored — the exact thing the pointer exists to prevent.
            $written = $disk->writeStream($key, $pipes[1]);

            fclose($pipes[1]);
            $exitCode = proc_close($process);
            $process = null;

            if ($exitCode !== 0) {
                $this->error("db:dump exited with {$exitCode}. Last output:");
                // Not a negative offset: seeking past the start of a short file fails.
                $this->line(substr((string) file_get_contents($progressLog), -2000));

                $this->discard($disk, $key);

                return self::FAILURE;
            }

            if ($written === false) {
                $this->error("Unable to write the dump to [{$key}].");
                $this->error("Check the disk's credentials and permissions.");

                $this->discard($disk, $key);

                return self::FAILURE;
            }

            if (($errors = UnscrubbedDataFilter::scanner()->errors()) !== []) {
                $this->error('The unscrubbed-data scan could not complete — not publishing the dump:');

                foreach ($errors as $label => $reason) {
                    $this->error("  {$label}: {$reason}");
                }

                $this->error('Fix the pattern in the Foggy config, then re-run.');

                $this->discard($disk, $key);

                return self::FAILURE;
            }

            if (($findings = UnscrubbedDataFilter::scanner()->findings()) !== []) {
                $this->error('Unscrubbed personal data found in the dump — not publishing it:');

                foreach ($findings as $label => $count) {
                    $this->error("  {$label}: {$count} match(es)");
                }

                $this->error('Add rules to the Foggy config for the offending columns, then re-run.');

                $this->discard($disk, $key);

                return self::FAILURE;
            }
        } catch (Throwable $exception) {
            // A disk configured with 'throw' => true raises instead of returning
            // false, which would otherwise skip the cleanup below and leave an
            // unscanned object on the disk.
            $this->error("Dumping to [{$key}] failed: {$exception->getMessage()}");

            $this->discard($disk, $key);

            return self::FAILURE;
        } finally {
            // Whatever happened, do not leave a child process, a pipe or the
            // temp file behind.
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }

            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }

            if (file_exists($progressLog)) {
                unlink($progressLog);
            }
        }

        // Written last, so it can only ever name a complete, scanned dump.
        if ($disk->put($pointer = $this->join($prefix, 'latest'), $key . "\n") === false) {
            $this->error("Dump uploaded to {$key}, but [{$pointer}] could not be updated.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'db-dump complete: %s, %s uncompressed, %ds',
            $key,
            $this->formatBytes(UnscrubbedDataFilter::scanner()->bytes()),
            (int) round(microtime(true) - $startedAt),
        ));

        return self::SUCCESS;
    }

    /**
     * The child process whose stdout is the dump.
     *
     * A separate process is what makes streaming possible at all: Foggy pushes
     * to an output, Flysystem pulls from a stream, and PHP has no way to bridge
     * push to pull in one process without buffering the whole dump. Overridable
     * so a consumer can point at a different binary — and so the surrounding
     * plumbing can be tested without a bootable `artisan`.
     *
     * @return list<string>
     */
    protected function dumpCommand(): array
    {
        return [
            PHP_BINARY,
            $this->getLaravel()->basePath('artisan'),
            'db:dump',
            ...array_values(array_filter([
                $this->option('connection') ? '--connection=' . $this->option('connection') : null,
                $this->option('config') ? '--config=' . $this->option('config') : null,
            ])),
        ];
    }

    /** Keeps a key relative when --prefix is empty; a leading slash breaks some adapters. */
    private function join(string $prefix, string $name): string
    {
        return $prefix === '' ? $name : "{$prefix}/{$name}";
    }

    /**
     * Removes a dump that must not be used.
     *
     * Best effort: a write-only credential is a perfectly sensible way to run
     * this, so say so rather than turning a clean failure into an exception.
     */
    private function discard(Filesystem $disk, string $key): void
    {
        try {
            // Refused deletes surface both ways: Laravel reports and returns
            // false unless the disk sets 'throw' => true. Staying quiet on the
            // false would leave a rejected dump on the disk unannounced.
            if ($disk->delete($key)) {
                return;
            }

            $this->warn("Could not remove {$key}.");
        } catch (Throwable $exception) {
            $this->warn("Could not remove {$key}: {$exception->getMessage()}");
        }

        $this->warn('Nothing points at it; rely on the bucket lifecycle rule to expire it.');
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1) . $units[$power];
    }
}
