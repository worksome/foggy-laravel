<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel;

use function Safe\fopen;
use function Safe\stream_wrapper_register;

/**
 * Reads through to a pipe as a stream of unknown size whose position ftell() can report.
 *
 * @internal
 */
final class ForwardOnlyStream
{
    private const string PROTOCOL = 'foggy-forward-only';

    /** @var resource|null */
    public $context;

    /** @var resource */
    private $inner;

    /**
     * @param resource $stream
     *
     * @return resource
     */
    public static function wrap($stream)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $wrapped = fopen(
            self::PROTOCOL . '://',
            'rb',
            false,
            stream_context_create([self::PROTOCOL => ['stream' => $stream]]),
        );

        // Only a failed seek marks a userspace stream unseekable; the S3 SDK would try to rewind it.
        @fseek($wrapped, 0);

        return $wrapped;
    }

    public function stream_open(string $path, string $mode, int $options, string|null &$openedPath): bool
    {
        $contextOptions = stream_context_get_options($this->context ?? stream_context_get_default());
        $stream = $contextOptions[self::PROTOCOL]['stream'] ?? null;

        if (! is_resource($stream)) {
            return false;
        }

        $this->inner = $stream;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        // Filters can yield nothing mid-stream; an empty read must only ever mean EOF.
        do {
            $chunk = fread($this->inner, max(1, $count));
        } while ($chunk === '' && ! feof($this->inner));

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return feof($this->inner);
    }

    // No stat means no size, so the S3 SDK streams a multipart upload instead of trusting a pipe's 0 bytes.
    public function stream_stat(): false
    {
        return false;
    }
}
