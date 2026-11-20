<?php

use App\Support\Secret;
use App\Support\SecretFileReader;
use App\Support\SecretUnavailableException;

/*
| All files here are temporary files created by the test with FAKE content.
| The real token / signing secret are never touched.
*/

const FAKE_FILE_SECRET = 'fake-file-secret-XYZ789';

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/shellseek-secret-test-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0700);
    $this->reader = new SecretFileReader;

    $this->makeFile = function (string $content, int $mode): string {
        $path = $this->dir.'/'.bin2hex(random_bytes(4));
        file_put_contents($path, $content);
        chmod($path, $mode);

        return $path;
    };
});

afterEach(function () {
    foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) {
            @chmod($f, 0600);
            @unlink($f);
        } elseif (is_dir($f) && ! in_array(basename($f), ['.', '..'], true)) {
            @rmdir($f);
        }
    }
    @rmdir($this->dir);
});

it('reads a 0600 file and trims surrounding whitespace', function () {
    $path = ($this->makeFile)("  ".FAKE_FILE_SECRET."\n", 0600);

    $secret = $this->reader->read($path);

    expect($secret)->toBeInstanceOf(Secret::class)
        ->and($secret->reveal())->toBe(FAKE_FILE_SECRET);
});

it('accepts a read-only 0400 file', function () {
    $path = ($this->makeFile)(FAKE_FILE_SECRET, 0400);

    expect($this->reader->read($path)->reveal())->toBe(FAKE_FILE_SECRET);
});

it('rejects files with group or other permission bits', function (int $mode) {
    $path = ($this->makeFile)(FAKE_FILE_SECRET, $mode);

    expect(fn () => $this->reader->read($path))
        ->toThrow(SecretUnavailableException::class, 'insecure permissions');
})->with([
    '0644' => 0644,
    '0640' => 0640,
    '0604' => 0604,
    '0660' => 0660,
    '0666' => 0666,
]);

it('rejects a missing file', function () {
    expect(fn () => $this->reader->read($this->dir.'/does-not-exist'))
        ->toThrow(SecretUnavailableException::class, 'does not exist');
});

it('rejects an empty path', function () {
    expect(fn () => $this->reader->read(''))
        ->toThrow(SecretUnavailableException::class, 'no path configured');
});

it('rejects a directory', function () {
    mkdir($this->dir.'/sub', 0700);

    expect(fn () => $this->reader->read($this->dir.'/sub'))
        ->toThrow(SecretUnavailableException::class, 'not a regular file');
});

it('rejects an empty or whitespace-only file', function (string $content) {
    $path = ($this->makeFile)($content, 0600);

    expect(fn () => $this->reader->read($path))
        ->toThrow(SecretUnavailableException::class, 'file is empty');
})->with(['empty' => '', 'whitespace' => " \n\t\n"]);

it('rejects an oversized file', function () {
    $path = ($this->makeFile)(str_repeat('a', SecretFileReader::MAX_BYTES + 1), 0600);

    expect(fn () => $this->reader->read($path))
        ->toThrow(SecretUnavailableException::class, 'too large');
});

it('never includes file contents in the error message or trace', function () {
    $path = ($this->makeFile)(FAKE_FILE_SECRET, 0644);

    try {
        $this->reader->read($path);
        $this->fail('expected exception');
    } catch (SecretUnavailableException $e) {
        expect(str_contains($e->getMessage(), FAKE_FILE_SECRET))->toBeFalse('content leaked in message')
            ->and(str_contains($e->getTraceAsString(), FAKE_FILE_SECRET))->toBeFalse('content leaked in trace')
            ->and($e->getMessage())->toContain($path);
    }
});
