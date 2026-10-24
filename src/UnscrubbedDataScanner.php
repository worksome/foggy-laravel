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
 * Only whole lines are scanned. A chunk cut mid-token makes a false word boundary,
 * so scanning raw chunks reported matches that were not in the dump.
 */
final class UnscrubbedDataScanner
{
    /**
     * Deliberately a short, high-confidence list. A scan that cries wolf gets
     * switched off, which is worse than not having one. Override per project
     * with the `foggy.scan_patterns` config key.
     */
    public const DEFAULT_PATTERNS = [
        'email address' => '/[\w.%+-]+@(?!example\.(?:org|com|net)(?![a-z]))[\w-]+\.[a-z]{2,}/i',
        'IBAN' => '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){3,7}\b/',
        'Danish CPR number' => '/\b\d{6}-\d{4}\b/',
    ];

    /**
     * An address can legally reach 254 characters, which is the longest thing
     * any default pattern can match, so the default overlap clears that.
     */
    public const DEFAULT_OVERLAP_BYTES = 256;

    /** A line longer than this is scanned in pieces, so a dump without newlines cannot exhaust memory. */
    public const DEFAULT_MAX_LINE_BYTES = 64 * 1024 * 1024;

    /** @var array<string, int> */
    private array $findings = [];

    /** @var array<string, string> */
    private array $errors = [];

    private int $bytes = 0;

    private string $overlap = '';

    private string $pending = '';

    /**
     * @param array<string, string> $patterns     Label => regex
     * @param int                   $overlapBytes Raise this if a custom pattern can match more
     *                                            than {@see self::DEFAULT_OVERLAP_BYTES}, or such
     *                                            a match could be missed on a chunk boundary.
     *                                            Applies only to lines over $maxLineBytes
     * @param int                   $maxLineBytes Longest line held in memory before it is scanned in pieces
     *
     * @throws InvalidArgumentException when a pattern will not compile, or the overlap is negative
     */
    public function __construct(
        private readonly array $patterns = self::DEFAULT_PATTERNS,
        private readonly int $overlapBytes = self::DEFAULT_OVERLAP_BYTES,
        private readonly int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
    ) {
        // A negative becomes a positive substr offset below, which would retain
        // nearly the whole haystack on every chunk and grow without bound.
        if ($overlapBytes < 0) {
            throw new InvalidArgumentException('Scan overlap must not be negative.');
        }

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
        $this->pending .= $chunk;

        $cut = strrpos($this->pending, "\n");

        if ($cut !== false) {
            $this->scanText($this->overlap . substr($this->pending, 0, $cut + 1));
            $this->overlap = '';
            $this->pending = substr($this->pending, $cut + 1);

            return;
        }

        if (strlen($this->pending) > $this->maxLineBytes) {
            $haystack = $this->overlap . $this->pending;
            $this->scanText($haystack);
            // Not substr(-0): that is substr(0), i.e. keep everything.
            $this->overlap = $this->overlapBytes === 0 ? '' : substr($haystack, -$this->overlapBytes);
            $this->pending = '';
        }
    }

    /** Scans whatever is left after the last newline. Call once the stream has ended. */
    public function finish(): void
    {
        if ($this->pending !== '' || $this->overlap !== '') {
            $this->scanText($this->overlap . $this->pending);
        }

        $this->overlap = '';
        $this->pending = '';
    }

    private function scanText(string $haystack): void
    {
        foreach ($this->patterns as $label => $pattern) {
            $matches = preg_match_all($pattern, $haystack);

            // A pattern that compiled can still fail here — a /u pattern meeting
            // the non-UTF-8 bytes of a BLOB, or a backtrack limit. false is not
            // "nothing found", so record it rather than letting it read as clean.
            if ($matches === false) {
                $this->errors[$label] = preg_last_error_msg();

                continue;
            }

            if ($matches > 0) {
                $this->findings[$label] = ($this->findings[$label] ?? 0) + $matches;
            }
        }
    }

    /**
     * Pattern label => number of matches. Exact, except on lines over the
     * line limit, where the overlap is scanned twice.
     *
     * @return array<string, int>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * Pattern label => why that pattern stopped being applied. Non-empty means
     * the dump was not fully checked, whatever {@see self::findings()} says.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Plaintext bytes seen, i.e. the uncompressed size of the dump. */
    public function bytes(): int
    {
        return $this->bytes;
    }
}
