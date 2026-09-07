<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Worksome\FoggyLaravel\DatabaseDumpToDiskCommand;
use Worksome\FoggyLaravel\Tests\Doubles\BinaryDumpToDiskCommand;
use Worksome\FoggyLaravel\Tests\Doubles\FailingDumpToDiskCommand;
use Worksome\FoggyLaravel\Tests\Doubles\StubDumpToDiskCommand;

beforeEach(fn () => Storage::fake('dumps'));

it('uploads a gzipped dump and points latest at it', function () {
    // Both doubles inherit the parent's signature, so they share its command
    // name. Register only the one under test, or the last registered wins.
    $this->app[Kernel::class]->registerCommand(new StubDumpToDiskCommand());

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])
        ->expectsOutputToContain('db-dump complete')
        ->assertOk();

    $disk = Storage::disk('dumps');
    $pointer = trim($disk->get('dumps/latest'));

    expect($pointer)->toMatch('#^dumps/dump-\d{4}-\d{2}-\d{2}-\d{6}-[0-9a-f]{6}\.sql\.gz$#')
        ->and($disk->exists($pointer))->toBeTrue()
        ->and(gzdecode($disk->get($pointer)))->toBe(StubDumpToDiskCommand::PAYLOAD);
});

it('refuses to publish a dump the scan objects to', function () {
    $this->app[Kernel::class]->registerCommand(new StubDumpToDiskCommand());

    // Matches the charset line every dump opens with, so the scan trips on the
    // stub's deliberately clean payload.
    config(['foggy.scan_patterns' => ['charset marker' => '/utf8mb4/']]);

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])
        ->expectsOutputToContain('Unscrubbed personal data found')
        ->expectsOutputToContain('charset marker')
        ->assertFailed();

    // The object was uploaded, but nothing advertises it as current.
    expect(Storage::disk('dumps')->exists('dumps/latest'))->toBeFalse();
});

it('gives each run its own key so a retry cannot overwrite a published dump', function () {
    $this->app[Kernel::class]->registerCommand(new StubDumpToDiskCommand());

    // Frozen, because a retry usually follows within the same second and that is
    // exactly when a timestamp alone stops being unique.
    $this->freezeTime();

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertOk();
    $first = trim(Storage::disk('dumps')->get('dumps/latest'));

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertOk();

    expect(trim(Storage::disk('dumps')->get('dumps/latest')))->not->toBe($first)
        ->and(Storage::disk('dumps')->exists($first))->toBeTrue();
});

it('does not delete a published dump when a same-second run is rejected', function () {
    $this->app[Kernel::class]->registerCommand(new StubDumpToDiskCommand());
    $this->freezeTime();

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertOk();
    $published = trim(Storage::disk('dumps')->get('dumps/latest'));

    // The second run trips the scan and discards its own object. Sharing a key
    // with the first would make that discard delete the dump latest names.
    config(['foggy.scan_patterns' => ['charset marker' => '/utf8mb4/']]);

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertFailed();

    expect(Storage::disk('dumps')->exists($published))->toBeTrue()
        ->and(trim(Storage::disk('dumps')->get('dumps/latest')))->toBe($published);
});

it('fails without publishing when the dump process errors', function () {
    $this->app[Kernel::class]->registerCommand(new FailingDumpToDiskCommand());

    $this->artisan(FailingDumpToDiskCommand::class, ['--disk' => 'dumps'])
        ->expectsOutputToContain('db:dump exited with 3')
        ->expectsOutputToContain('something broke')
        ->assertFailed();

    expect(Storage::disk('dumps')->exists('dumps/latest'))->toBeFalse();
});

it('shells out to artisan db:dump, passing connection and config through', function () {
    $command = new DatabaseDumpToDiskCommand();
    $command->setLaravel($this->app);

    $input = new Symfony\Component\Console\Input\ArrayInput(
        ['--connection' => 'mysql-replica', '--config' => '/tmp/foggy.json'],
        $command->getDefinition(),
    );
    (new ReflectionProperty(Command::class, 'input'))->setValue($command, $input);

    // Pins the production child process, which the doubles above deliberately
    // replace: testbench's skeleton artisan cannot bootstrap on its own.
    expect((new ReflectionMethod($command, 'dumpCommand'))->invoke($command))->toBe([
        PHP_BINARY,
        base_path('artisan'),
        'db:dump',
        '--connection=mysql-replica',
        '--config=/tmp/foggy.json',
    ]);
});

it('writes to the disk root when the prefix is empty', function () {
    $this->app[Kernel::class]->registerCommand(new StubDumpToDiskCommand());

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps', '--prefix' => ''])->assertOk();

    // A leading slash breaks some adapters, so the key has to stay relative.
    expect(trim(Storage::disk('dumps')->get('latest')))->toMatch('#^dump-[\d-]+-[0-9a-f]{6}\.sql\.gz$#');
});

it('removes the object it refuses to publish', function () {
    $this->app[Kernel::class]->registerCommand(new StubDumpToDiskCommand());

    config(['foggy.scan_patterns' => ['charset marker' => '/utf8mb4/']]);

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertFailed();

    expect(Storage::disk('dumps')->files('dumps'))->toBe([]);
});

it('removes the object left behind by a failed dump', function () {
    $this->app[Kernel::class]->registerCommand(new FailingDumpToDiskCommand());

    $this->artisan(FailingDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertFailed();

    expect(Storage::disk('dumps')->files('dumps'))->toBe([]);
});

it('fails without publishing when the upload itself fails', function () {
    // writeStream returns false rather than throwing unless the disk sets
    // 'throw' => true, so a package cannot rely on an exception here.
    $disk = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('writeStream')->once()->andReturnUsing(function ($path, $resource) {
        stream_get_contents($resource); // drain, as the real adapter would

        return false;
    });
    // Asserting on the calls rather than the output: what matters is that the
    // pointer is never written and the partial object is cleaned up.
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    $disk->shouldNotReceive('put');

    $factory = Mockery::mock(Illuminate\Contracts\Filesystem\Factory::class);
    $factory->shouldReceive('disk')->andReturn($disk);
    Storage::swap($factory);

    $this->app[Kernel::class]->registerCommand(new StubDumpToDiskCommand());

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertFailed();
});

it('fails when the dump uploads but the pointer cannot be written', function () {
    $disk = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('writeStream')->once()->andReturnUsing(function ($path, $resource) {
        stream_get_contents($resource); // drain, as the real adapter would

        return true;
    });
    $disk->shouldReceive('put')->once()->andReturnFalse();

    $factory = Mockery::mock(Illuminate\Contracts\Filesystem\Factory::class);
    $factory->shouldReceive('disk')->andReturn($disk);
    Storage::swap($factory);

    $this->app[Kernel::class]->registerCommand(new StubDumpToDiskCommand());

    // A dump that uploaded but is not advertised is a failure, not a success.
    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertFailed();
});

it('cleans up and fails when the upload throws instead of returning false', function () {
    // What a disk configured with 'throw' => true does. Without a catch this
    // skipped the discard and left the object behind, unscanned.
    $disk = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('writeStream')->once()->andReturnUsing(function ($path, $resource) {
        stream_get_contents($resource); // drain, as the real adapter would

        throw UnableToWriteFile::atLocation($path, 'denied');
    });
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    $disk->shouldNotReceive('put');

    $factory = Mockery::mock(Illuminate\Contracts\Filesystem\Factory::class);
    $factory->shouldReceive('disk')->andReturn($disk);
    Storage::swap($factory);

    $this->app[Kernel::class]->registerCommand(new StubDumpToDiskCommand());

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertFailed();
});

it('refuses to publish when the scan could not run over the whole dump', function () {
    $this->app[Kernel::class]->registerCommand(new BinaryDumpToDiskCommand());

    // Valid pattern, but /u against the dump's non-UTF-8 bytes fails at scan
    // time. An unfinished scan is no basis for publishing.
    config(['foggy.scan_patterns' => ['unicode word' => '/\p{L}+@acme/u']]);

    $this->artisan(BinaryDumpToDiskCommand::class, ['--disk' => 'dumps'])
        ->expectsOutputToContain('scan could not complete')
        ->expectsOutputToContain('unicode word')
        ->assertFailed();

    expect(Storage::disk('dumps')->files('dumps'))->toBe([])
        ->and(Storage::disk('dumps')->exists('dumps/latest'))->toBeFalse();
});
