<?php

use App\Services\ScopeEnforcer\Decision;
use App\Services\ScopeEnforcer\FailureCode;
use App\Services\ScopeEnforcer\ScopeEnforcerClient;
use App\Services\ScopeEnforcer\ValidationRequest;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
| Real-socket tests on 127.0.0.1 only (no Http::fake, never VM 2).
| They prove that actual curl timeouts and refused connections fail closed
| within the configured limits and follow the retry policy.
*/

beforeEach(function () {
    Http::preventStrayRequests(false); // real sockets on 127.0.0.1 only
    $this->dir = sys_get_temp_dir().'/shellseek-net-test-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0700);
    $this->tokenFile = $this->dir.'/token';
    file_put_contents($this->tokenFile, 'fake-network-test-token');
    chmod($this->tokenFile, 0600);

    config([
        'scope_enforcer.token_file' => $this->tokenFile,
        'scope_enforcer.connect_timeout' => 1,
        'scope_enforcer.timeout' => 1,
    ]);

    $this->attempts = 0;
    Event::listen(ConnectionFailed::class, function () {
        $this->attempts++;
    });

    $this->request = new ValidationRequest('REQ-net-001', 'nmap', 'service_detection', '192.168.100.50', ['-sV', '-p', '80']);
});

afterEach(function () {
    if (isset($this->server) && is_resource($this->server)) {
        fclose($this->server);
    }
    @unlink($this->tokenFile);
    @rmdir($this->dir);
});

it('fails closed on a real read timeout without retrying', function () {
    // Listening socket that never accepts: the kernel completes the TCP
    // handshake (backlog), the request is sent, no response ever comes.
    $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($this->server)->not->toBeFalse();
    $port = (int) substr(strrchr(stream_socket_get_name($this->server, false), ':'), 1);
    config(['scope_enforcer.base_url' => "http://127.0.0.1:{$port}"]);

    $started = microtime(true);
    $result = app(ScopeEnforcerClient::class)->validate($this->request);
    $elapsed = microtime(true) - $started;

    fwrite(STDERR, sprintf("\n    [read timeout] elapsed=%.2fs attempts=%d code=%s\n", $elapsed, $this->attempts, $result->failureCode?->value));

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->failureCode)->toBe(FailureCode::Timeout)
        ->and($result->isAllowCandidate())->toBeFalse()
        ->and($this->attempts)->toBe(1)
        ->and($elapsed)->toBeGreaterThanOrEqual(0.9)
        ->and($elapsed)->toBeLessThan(1.9);
});

it('fails closed on a real refused connection after exactly one retry', function () {
    // Reserve a free port, then close it so connecting is refused.
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    config(['scope_enforcer.base_url' => "http://127.0.0.1:{$port}"]);

    $started = microtime(true);
    $result = app(ScopeEnforcerClient::class)->validate($this->request);
    $elapsed = microtime(true) - $started;

    fwrite(STDERR, sprintf("\n    [refused] elapsed=%.2fs attempts=%d code=%s\n", $elapsed, $this->attempts, $result->failureCode?->value));

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->failureCode)->toBe(FailureCode::Unreachable)
        ->and($this->attempts)->toBe(ScopeEnforcerClient::MAX_ATTEMPTS)
        ->and($elapsed)->toBeGreaterThanOrEqual(ScopeEnforcerClient::RETRY_DELAY_MS / 1000)
        ->and($elapsed)->toBeLessThan(1.5);
});

it('reports a real refused connection as unreachable in health()', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    config(['scope_enforcer.base_url' => "http://127.0.0.1:{$port}"]);

    $health = app(ScopeEnforcerClient::class)->health();

    expect($health->reachable)->toBeFalse()->and($health->healthy)->toBeFalse();
});
