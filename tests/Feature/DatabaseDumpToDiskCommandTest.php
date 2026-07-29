<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Worksome\FoggyLaravel\DatabaseDumpToDiskCommand;
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

    expect($pointer)->toMatch('#^dumps/dump-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$#')
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

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertOk();
    $first = trim(Storage::disk('dumps')->get('dumps/latest'));

    $this->travel(1)->second();

    $this->artisan(StubDumpToDiskCommand::class, ['--disk' => 'dumps'])->assertOk();

    expect(trim(Storage::disk('dumps')->get('dumps/latest')))->not->toBe($first)
        ->and(Storage::disk('dumps')->exists($first))->toBeTrue();
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
    expect(trim(Storage::disk('dumps')->get('latest')))->toMatch('#^dump-[\d-]+\.sql\.gz$#');
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
