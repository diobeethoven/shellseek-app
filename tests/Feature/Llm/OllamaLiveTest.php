<?php

use App\Services\Command\CommandParsingService;
use App\Services\Llm\LlmService;
use Illuminate\Support\Facades\Http;

/*
| REAL call to the local Ollama (127.0.0.1:11434) — opt-in only:
|     ./vendor/bin/pest --group=ollama
| Excluded from the default suite (slow, non-deterministic).
| Only asks the LLM for a command: nothing is executed, VM 2 is not contacted.
*/

it('gets a structurally valid command from the real local model', function () {
    Http::preventStrayRequests(false); // opt-in real call to loopback Ollama
    expect(parse_url(config('llm.base_url'), PHP_URL_HOST))->toBeIn(['127.0.0.1', 'localhost', '[::1]']);

    $instruction = 'scan services on 192.168.100.50 ports 80 and 443';
    $result = app(LlmService::class)->generateCommand($instruction);

    fwrite(STDERR, "\n    instruction : {$instruction}");
    fwrite(STDERR, "\n    model       : {$result->model}");
    fwrite(STDERR, "\n    duration_ms : ".($result->durationMs ?? 'n/a'));
    fwrite(STDERR, "\n    failure     : ".($result->failure?->value ?? 'none'));
    fwrite(STDERR, "\n    raw output  : ".($result->content ?? 'null')."\n");

    expect($result->isSuccess())->toBeTrue();

    $parsed = app(CommandParsingService::class)->parse($result->content);
    fwrite(STDERR, '    parse       : '.($parsed->isSuccess() ? 'ok' : $parsed->message())."\n");

    expect($parsed->isSuccess())->toBeTrue();
})->group('ollama');
