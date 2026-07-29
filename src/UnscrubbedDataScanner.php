<?php

declare(strict_types=1);

namespace Worksome\FoggyLaravel;

use InvalidArgumentException;

/**
 * Watches a dump for personal data that the Foggy config should have scrubbed.
 *
 * This is a tripwire for the gap a Foggy config cannot close on its own: a new
 * column on a table that is already `withData: true` is dumped raw, and nothing
 * about the config or the schema reveals that.
 *
 * Chunks arrive in whatever sizes the stream hands over, so the tail of each one
 * is carried into the next — otherwise a value straddling a boundary would go
 * unnoticed.
 */
final class UnscrubbedDataScanner
{
    /**
     * Deliberately a short, high-confidence list. A scan that cries wolf gets
     * switched off, which is worse than not having one. Override per project
     * with the `foggy.scan_patterns` config key.
     */
    public const DEFAULT_PATTERNS = [
        'email address' => '/[\w.%+-]+@(?!example\.(?:org|com|net)\b)[\w-]+\.[a-z]{2,}/i',
        'IBAN' => '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){3,7}\b/',
        'Danish CPR number' => '/\b\d{6}-\d{4}\b/',
    ];

    /**
     * An address can legally reach 254 characters, which is the longest thing
     * any default pattern can match, so the default overlap clears that.
     */
    public const DEFAULT_OVERLAP_BYTES = 256;

    /** @var array<string, int> */
    private array $findings = [];

    private int $bytes = 0;

    private string $overlap = '';

    /**
     * @param array<string, string> $patterns     Label => regex
     * @param int                   $overlapBytes Raise this if a custom pattern can match more
     *                                            than {@see self::DEFAULT_OVERLAP_BYTES}, or such
     *                                            a match could be missed on a chunk boundary
     *
     * @throws InvalidArgumentException when a pattern will not compile
     */
    public function __construct(
        private readonly array $patterns = self::DEFAULT_PATTERNS,
        private readonly int $overlapBytes = self::DEFAULT_OVERLAP_BYTES,
    ) {
        foreach ($patterns as $label => $pattern) {
            // Fail closed, and fail now: a pattern that cannot compile makes
            // preg_match_all return false, which would silently stop checking
            // for that class of data halfway through a dump.
            if (@preg_match($pattern, '') === false) {
                throw new InvalidArgumentException("Scan pattern [{$label}] is not a valid regular expression.");
            }
        }
    }

    public function scan(string $chunk): void
    {
        $this->bytes += strlen($chunk);

        $haystack = $this->overlap . $chunk;

        foreach ($this->patterns as $label => $pattern) {
            $matches = preg_match_all($pattern, $haystack);

            if ($matches > 0) {
                $this->findings[$label] = ($this->findings[$label] ?? 0) + $matches;
            }
        }

        $this->overlap = substr($haystack, -$this->overlapBytes);
    }

    /**
     * Pattern label => number of matches. Counts are approximate, because the
     * overlap between chunks is scanned twice; presence is what matters.
     *
     * @return array<string, int>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /** Plaintext bytes seen, i.e. the uncompressed size of the dump. */
    public function bytes(): int
    {
        return $this->bytes;
    }
}
