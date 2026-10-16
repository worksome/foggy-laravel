<?php

declare(strict_types=1);

use Worksome\FoggyLaravel\ForwardOnlyStream;

it('reads through to a pipe with no size, no seeking and a position ftell can report', function () {
    $process = proc_open([PHP_BINARY, '-r', 'echo "hello world";'], [1 => ['pipe', 'w']], $pipes);
    $stream = ForwardOnlyStream::wrap($pipes[1]);

    expect(fstat($stream))->toBeFalse()
        ->and(stream_get_meta_data($stream)['seekable'])->toBeFalse()
        ->and(fread($stream, 5))->toBe('hello')
        ->and(ftell($stream))->toBe(5)
        ->and(stream_get_contents($stream))->toBe(' world')
        ->and(feof($stream))->toBeTrue();

    fclose($stream);
    fclose($pipes[1]);
    proc_close($process);
});
