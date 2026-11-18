<?php

declare(strict_types=1);

use Worksome\FoggyLaravel\UnscrubbedDataFilter;

beforeEach(fn () => UnscrubbedDataFilter::register());

/** Runs a payload through the same filter chain the command uses. */
function pipe(string $payload): string
{
    $source = fopen('php://temp', 'r+');
    fwrite($source, $payload);
    rewind($source);

    stream_filter_append($source, UnscrubbedDataFilter::NAME, STREAM_FILTER_READ);
    stream_filter_append($source, 'zlib.deflate', STREAM_FILTER_READ, ['level' => 6, 'window' => 31]);

    $compressed = stream_get_contents($source);
    fclose($source);

    return $compressed;
}

it('passes the payload through intact and still produces valid gzip', function () {
    $payload = str_repeat("INSERT INTO `things` VALUES ('a', 'b');\n", 500);

    $compressed = pipe($payload);

    // Regression guard: returning PSFS_FEED_ME on the closing call stopped the
    // chain, zlib never flushed, and the archive was silently corrupt.
    expect(gzdecode($compressed))->toBe($payload)
        ->and(UnscrubbedDataFilter::scanner()->bytes())->toBe(strlen($payload))
        ->and(UnscrubbedDataFilter::scanner()->findings())->toBe([]);
});

it('surfaces findings from the payload it compressed', function () {
    expect(fn () => pipe("INSERT INTO `notes` VALUES ('mail jane.doe@acme-corp.com');\n"))
        ->toThrow(RuntimeException::class, 'rejected the dump');

    expect(UnscrubbedDataFilter::scanner()->findings())->toHaveKey('email address');
});

it('fails the final read once the whole payload has been scanned', function () {
    $payload = str_repeat("INSERT INTO `things` VALUES ('a', 'b');\n", 5000) . "('jane.doe@acme-corp.com');\n";

    expect(fn () => pipe($payload))->toThrow(RuntimeException::class, 'rejected the dump')
        ->and(UnscrubbedDataFilter::scanner()->bytes())->toBe(strlen($payload));
});

it('catches a long custom-pattern match across a bucket boundary without widening the overlap', function () {
    $pattern = ['long token' => '/TOK-[A-Z0-9]{400}/'];
    // php://temp feeds the filter in 8192-byte buckets, so the token straddles the first boundary.
    $payload = str_repeat('x', 8192 - 300) . 'TOK-' . str_repeat('A', 400) . "\n";

    UnscrubbedDataFilter::register($pattern);

    expect(fn () => pipe($payload))->toThrow(RuntimeException::class, 'rejected the dump');
});

it('does not reject a clean dump because a bucket boundary cut a value', function () {
    // Cut at the bucket boundary, "redacted@example.or" escaped the example.org exclusion.
    $row = "('redacted@example.org'),\n";
    $payload = str_repeat('x', 8192 - 22) . "\n" . str_repeat($row, 200);

    expect(gzdecode(pipe($payload)))->toBe($payload)
        ->and(UnscrubbedDataFilter::scanner()->findings())->toBe([]);
});

it('can be registered repeatedly without losing the filter', function () {
    UnscrubbedDataFilter::register();
    UnscrubbedDataFilter::register();

    expect(stream_get_filters())->toContain(UnscrubbedDataFilter::NAME)
        ->and(gzdecode(pipe('hello')))->toBe('hello');
});
