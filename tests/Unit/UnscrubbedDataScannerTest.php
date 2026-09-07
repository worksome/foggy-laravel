<?php

declare(strict_types=1);

use Worksome\FoggyLaravel\UnscrubbedDataScanner;

it('flags an email address that survived scrubbing', function () {
    $scanner = new UnscrubbedDataScanner();

    $scanner->scan("INSERT INTO `notes` VALUES ('ping jane.doe@acme-corp.com about it');\n");

    expect($scanner->findings())->toHaveKey('email address');
});

it('ignores the addresses a Foggy rule generates', function () {
    $scanner = new UnscrubbedDataScanner();

    $scanner->scan("('mail_12@example.org'),('mail_13@example.com')\n");

    expect($scanner->findings())->toBe([]);
});

it('flags IBANs and CPR numbers', function (string $payload, string $expected) {
    $scanner = new UnscrubbedDataScanner();

    $scanner->scan($payload);

    expect($scanner->findings())->toHaveKey($expected);
})->with([
    ['bank details DK50 0040 0440 1162 43 here', 'IBAN'],
    ['born 010190-1234 apparently', 'Danish CPR number'],
]);

it('catches a value split across two chunks', function () {
    $scanner = new UnscrubbedDataScanner();

    // The case a naive per-chunk scan misses, and the reason the scanner keeps
    // an overlap. Remove the overlap and this is the test that fails.
    $scanner->scan(str_repeat('x', 40) . 'hidden@acme');
    $scanner->scan("-corp.com');\n");

    expect($scanner->findings())->toHaveKey('email address');
});

it('accepts project specific patterns', function () {
    $scanner = new UnscrubbedDataScanner(['employee number' => '/\bEMP-\d{5}\b/']);

    $scanner->scan('assigned to EMP-40127 last week, contact them@acme-corp.com');

    // Only the supplied pattern applies — the defaults are replaced, not merged,
    // so a project can drop one that is noisy against its data.
    expect($scanner->findings())->toBe(['employee number' => 1]);
});

it('counts every byte it is given', function () {
    $scanner = new UnscrubbedDataScanner();

    $scanner->scan(str_repeat('a', 100));
    $scanner->scan(str_repeat('b', 50));

    expect($scanner->bytes())->toBe(150);
});

it('refuses a pattern that will not compile, instead of skipping it silently', function () {
    // preg_match_all returns false on a bad pattern, which would mean that class
    // of data stops being checked for without anything saying so.
    expect(fn () => new UnscrubbedDataScanner(['broken' => '/[unterminated/']))
        ->toThrow(InvalidArgumentException::class, 'Scan pattern [broken]');
});

it('still catches a long address split across chunks', function () {
    $scanner = new UnscrubbedDataScanner();

    // An address may be up to 254 characters, so the overlap has to clear that.
    $local = str_repeat('a', 200);
    $scanner->scan("padding {$local}@acme");
    $scanner->scan("-corp.com');\n");

    expect($scanner->findings())->toHaveKey('email address');
});

it('lets a project widen the overlap for a longer custom pattern', function () {
    $pattern = ['long token' => '/TOK-[A-Z0-9]{400}/'];
    $token = 'TOK-' . str_repeat('A', 400);

    // The chunks have to be larger than the overlap for the limit to bite: the
    // first ends 300 characters into the token, and a 256-byte overlap keeps
    // too little of it to carry the TOK- prefix into the second chunk.
    $chunks = [str_repeat('x', 300) . substr($token, 0, 300), substr($token, 300)];

    $narrow = new UnscrubbedDataScanner($pattern);
    $wide = new UnscrubbedDataScanner($pattern, overlapBytes: 512);

    foreach ($chunks as $chunk) {
        $narrow->scan($chunk);
        $wide->scan($chunk);
    }

    expect($narrow->findings())->toBe([])
        ->and($wide->findings())->toHaveKey('long token');
});

it('records a pattern that fails partway through a scan, instead of reading it as clean', function () {
    // Compiles, so the constructor check passes, but /u against invalid UTF-8
    // makes preg_match_all return false — which used to tally as no matches.
    $scanner = new UnscrubbedDataScanner(['unicode word' => '/\p{L}+@acme/u']);

    $scanner->scan("\xC3\x28 someone@acme");

    expect($scanner->errors())->toHaveKey('unicode word')
        ->and($scanner->findings())->toBe([]);
});

it('reports no errors for a scan that completes', function () {
    $scanner = new UnscrubbedDataScanner();

    $scanner->scan("nothing to see here\n");

    expect($scanner->errors())->toBe([]);
});
