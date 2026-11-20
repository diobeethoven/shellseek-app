@extends('layouts.app')

@section('title', 'System Status')
@section('subtitle', 'Checked '.date('H:i:s', $status->checkedAt).' (cached up to 15 s)')

@section('actions')
    <a class="btn btn-ghost" href="{{ route('status', ['refresh' => 1]) }}">Refresh</a>
@endsection

@section('content')
    @php $se = $status->scopeEnforcer; $llm = $status->llm; @endphp

    <div class="alert tone-{{ $status->executionPossible() ? 'ok' : 'deny' }}" role="status">
        <span class="alert-icon">{{ $status->executionPossible() ? '✓' : '!' }}</span>
        <div>
            <strong>Fail-closed mode: always on</strong>
            @if (! $se->healthy)
                The Scope Enforcer is not available, so every request is treated as DENY and nothing is executed.
            @elseif (! $status->signatureVerificationAvailable)
                Signature verification is not configured, so no command can be executed (ALLOW decisions are shown but blocked).
            @else
                Commands run only with a signed, unexpired ALLOW bound to the exact command.
            @endif
        </div>
    </div>

    <div class="grid grid-3">
        <section class="card">
            <div class="card-head"><h2>Scope Enforcer (VM 2)</h2><span class="dot {{ $se->healthy ? 'ok' : 'deny' }}"></span></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>State</dt><dd id="se-state">{{ $se->healthy ? 'Healthy' : ($se->reachable ? 'Unhealthy' : 'Unreachable') }}</dd>
                    <dt>Endpoint</dt><dd class="mono">{{ config('scope_enforcer.base_url') }}/health</dd>
                    <dt>HTTP status</dt><dd>{{ $se->httpStatus ?? '—' }}</dd>
                    <dt>Reported status</dt><dd class="mono">{{ $se->status ?? '—' }}</dd>
                    <dt>Latency</dt><dd>{{ $se->latencyMs !== null ? $se->latencyMs.' ms' : '—' }}</dd>
                </dl>
                <p class="muted" style="margin-bottom:0">{{ $se->message }}</p>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Local LLM (Ollama)</h2><span class="dot {{ $llm->reachable && $llm->modelAvailable ? 'ok' : 'warn' }}"></span></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>State</dt><dd id="llm-state">{{ $llm->reachable ? ($llm->modelAvailable ? 'Ready' : 'Model missing') : 'Unreachable' }}</dd>
                    <dt>Endpoint</dt><dd class="mono">{{ config('llm.base_url') }}</dd>
                    <dt>Model</dt><dd class="mono">{{ $llm->model }}</dd>
                </dl>
                <p class="muted" style="margin-bottom:0">{{ $llm->message }}</p>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Execution safeguards</h2><span class="dot {{ $status->signatureVerificationAvailable ? 'ok' : 'warn' }}"></span></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Fail-closed</dt><dd>Always on</dd>
                    <dt>Signature check</dt><dd id="verifier-state">{{ $status->signatureVerificationAvailable ? 'HMAC-SHA256 verifier active' : 'Unavailable – no command can execute' }}</dd>
                    <dt>WARNING</dt><dd>Held, never executed</dd>
                    <dt>Shell</dt><dd>Never used (argument arrays only)</dd>
                </dl>
            </div>
        </section>
    </div>
@endsection
