# Laravel Foggy

This package is a Laravel wrapper for Foggy.

Configuration of the plugin can be found at [Foggy's docs](https://github.com/worksome/foggy).

## Install

Via Composer

```shell
composer require worksome/foggy-laravel
```

## Usage

This package adds a new artisan command for running Foggy. Simply type:

````shell
php artisan db:dump
````

It will by default assume that the Foggy config file, will be in `foggy.json` in the root of the project.  
A configuration file can be supplied by using `--config` argument.

The artisan command by default will make the database dump to `stdout`. To pass the output to a file, use the `--output` (`-o`) option. 

```shell
php artisan db:dump --output scrubbed-dump.sql
```

Foggy also supports specifying a custom database connection:

```shell
php artisan db:dump --connection mysql
```

## Uploading a dump to a filesystem disk

`db:dump-to-disk` streams the scrubbed dump through gzip and onto any configured
filesystem disk. Nothing is written to local disk on the way, so it works in a
container with no spare space — which starts to matter once a dump is tens of
gigabytes.

```shell
php artisan db:dump-to-disk --disk=dumps --connection=replica
```

It writes two objects:

- `dumps/dump-YYYY-MM-DD-HHMMSS-xxxxxx.sql.gz`, the dump itself
- `dumps/latest`, naming the most recent **complete** dump

Read the pointer rather than guessing a filename. Keys carry a timestamp and a
random suffix, so a re-run never reuses the key of a published dump even when it
starts in the same second, and `latest` is only written after the dump has
finished and passed the scan below — so a truncated or rejected dump is never the
one consumers pull.

Options: `--disk`, `--prefix` (default `dumps`), `--name` (default `dump`),
plus `--connection` and `--config`, which are passed through to `db:dump`.

### Checking that the rules actually worked

A Foggy config can only protect the columns it knows about. A new column on a
table that is already `withData: true` is dumped raw, and neither the config nor
the schema reveals that.

So the upload scans its own output, and fails without writing `latest` if it
finds anything. The defaults look for email addresses, IBANs and Danish CPR
numbers — deliberately a short, high-confidence list, because a scan that cries
wolf gets switched off. Replace them per project:

```php
// config/foggy.php
return [
    'scan_patterns' => [
        'email address' => '/[\w.%+-]+@(?!example\.(?:org|com|net)\b)[\w-]+\.[a-z]{2,}/i',
        'employee number' => '/\bEMP-\d{5}\b/',
    ],
];
```

The patterns are replaced, not merged, so a default that is noisy against your
data can simply be dropped.

One constraint: the scan reads the dump in chunks and carries 256 bytes between
them, so a pattern that can match more than that could miss an occurrence
straddling a chunk boundary. The defaults are well inside it (an email address
caps out at 254 characters). If you add something longer, widen the window:

```php
new UnscrubbedDataScanner($patterns, overlapBytes: 1024);
```

Patterns are validated when the scanner is constructed — one that will not
compile raises `InvalidArgumentException` rather than silently matching nothing.
A pattern that compiles but then fails mid-dump, such as a `/u` pattern meeting
the non-UTF-8 bytes of a BLOB column, fails the run too: an unfinished scan is
not evidence that the dump is clean.
