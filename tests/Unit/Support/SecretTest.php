<?php

use App\Support\Secret;

const FAKE_SECRET = 'fake-test-secret-ABC123';

it('reveals the raw value only through reveal()', function () {
    expect((new Secret(FAKE_SECRET))->reveal())->toBe(FAKE_SECRET);
});

it('masks the value in every common representation', function () {
    $secret = new Secret(FAKE_SECRET);

    ob_start();
    var_dump($secret);
    $dumped = ob_get_clean();

    $representations = [
        'string cast' => (string) $secret,
        'interpolation' => "token={$secret}",
        'json_encode' => json_encode(['token' => $secret]),
        'var_export' => var_export($secret, true),
        'print_r' => print_r($secret, true),
        'var_dump' => $dumped,
        'array cast' => var_export((array) $secret, true),
        'get_object_vars' => var_export(get_object_vars($secret), true),
    ];

    foreach ($representations as $label => $output) {
        // Custom message on purpose: never let a failure print the secret.
        expect(str_contains($output, FAKE_SECRET))->toBeFalse("secret leaked via {$label}");
    }

    expect((string) $secret)->toBe(Secret::MASK)
        ->and(json_encode($secret))->toBe('"'.Secret::MASK.'"');
});

it('refuses to be serialized or cloned', function () {
    $secret = new Secret(FAKE_SECRET);

    expect(fn () => serialize($secret))->toThrow(LogicException::class)
        ->and(fn () => clone $secret)->toThrow(LogicException::class);
});

it('rejects an empty value', function () {
    expect(fn () => new Secret(''))->toThrow(LogicException::class);
});

it('does not expose the value in exception stack traces', function () {
    $thrower = function (#[\SensitiveParameter] string $raw) {
        new Secret($raw);
        throw new RuntimeException('boom');
    };

    try {
        $thrower(FAKE_SECRET);
        $this->fail('expected exception');
    } catch (RuntimeException $e) {
        expect(str_contains($e->getTraceAsString(), FAKE_SECRET))->toBeFalse('secret leaked in trace');
    }
});
