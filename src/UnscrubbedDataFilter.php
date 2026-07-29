<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel;

use php_user_filter;

/**
 * Stream filter that feeds a dump through {@see UnscrubbedDataScanner}.
 *
 * Attach it as a read filter ahead of `zlib.deflate` so it sees plaintext. The
 * scanner is static because the stream layer owns filter instances and the
 * caller needs the verdict after the stream has been consumed — which makes this
 * single-use per process, fine for a console command.
 */
final class UnscrubbedDataFilter extends php_user_filter
{
    public const NAME = 'worksome.unscrubbed-data';

    private static UnscrubbedDataScanner $scanner;

    /**
     * Registers the filter and starts a fresh scan.
     *
     * Filter names are global to the process and registering one twice just
     * returns false, so the guard stops a second call in the same process (a
     * queue worker, or two tests) from quietly doing nothing.
     *
     * @param array<string, string>|null $patterns Label => regex
     */
    public static function register(array|null $patterns = null): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }

        self::$scanner = new UnscrubbedDataScanner(
            $patterns === null || $patterns === [] ? UnscrubbedDataScanner::DEFAULT_PATTERNS : $patterns,
        );
    }

    public static function scanner(): UnscrubbedDataScanner
    {
        return self::$scanner ??= new UnscrubbedDataScanner();
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $passed = false;

        while ($bucket = stream_bucket_make_writeable($in)) {
            self::scanner()->scan($bucket->data);

            $consumed += $bucket->datalen;

            stream_bucket_append($out, $bucket);
            $passed = true;
        }

        // On the closing call there are no buckets left, but answering
        // PSFS_FEED_ME here stops the chain and the next filter never gets to
        // flush — which silently produced a corrupt gzip.
        return $passed || $closing ? PSFS_PASS_ON : PSFS_FEED_ME;
    }
}
