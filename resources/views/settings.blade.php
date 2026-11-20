@extends('layouts.app')

@section('title', 'Settings')
@section('subtitle', 'Read-only. Change values in config files / .env and restart; secrets are never shown.')

@section('content')
    <div class="grid grid-2">
        <section class="card">
            <div class="card-head"><h2>Scope Enforcer client</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Base URL</dt><dd class="mono">{{ $scopeEnforcer['base_url'] }}</dd>
                    <dt>Connect / read timeout</dt><dd>{{ $scopeEnforcer['connect_timeout'] }} s / {{ $scopeEnforcer['timeout'] }} s</dd>
                    <dt>Health timeout</dt><dd>{{ $scopeEnforcer['health_timeout'] }} s</dd>
                    <dt>requested_by</dt><dd class="mono">{{ $scopeEnforcer['requested_by'] }}</dd>
                    <dt>Max authorization TTL</dt><dd>{{ $scopeEnforcer['authorization_ttl_max'] }} s</dd>
                    <dt>Clock skew tolerance</dt><dd>{{ $scopeEnforcer['clock_skew'] }} s</dd>
                    <dt>Max request size</dt><dd>{{ number_format($scopeEnforcer['max_request_bytes']) }} bytes</dd>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Secret files</h2><span class="hint">Contents are never read or displayed here</span></div>
            <div class="card-body">
                <table>
                    <thead><tr><th>Secret</th><th>Path</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($secretFiles as $name => $file)
                        <tr>
                            <td>{{ $name }}</td>
                            <td class="mono">{{ $file['path'] }}</td>
                            <td>
                                @if (! $file['present'])
                                    <span class="badge badge-deny">missing</span>
                                @elseif ($file['secure'])
                                    <span class="badge badge-allow">present · {{ $file['mode'] }}</span>
                                @else
                                    <span class="badge badge-warning">insecure · {{ $file['mode'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Local LLM</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Base URL</dt><dd class="mono">{{ $llm['base_url'] }}</dd>
                    <dt>Model</dt><dd class="mono">{{ $llm['model'] }}</dd>
                    <dt>Timeout</dt><dd>{{ $llm['timeout'] }} s</dd>
                    <dt>Temperature</dt><dd>{{ $llm['temperature'] }}</dd>
                    <dt>Max instruction</dt><dd>{{ $llm['max_instruction_chars'] }} characters</dd>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Execution</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Signature verification</dt><dd>{{ $signatureVerificationAvailable ? 'Active' : 'Not configured – execution disabled' }}</dd>
                    <dt>WARNING approval</dt><dd>Disabled</dd>
                    <dt>Max timeout</dt><dd>{{ $execution['max_timeout'] }} s</dd>
                    <dt>Output cap</dt><dd>{{ number_format($execution['max_output_bytes']) }} bytes</dd>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Activity history</h2><span class="hint">config/history.php</span></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Archive / delete from Log</dt><dd>{{ ($history['management_enabled'] ?? false) ? 'Enabled' : 'Disabled (read-only)' }}</dd>
                    <dt>Running request locked for</dt><dd>{{ $history['stale_after_seconds'] ?? 900 }} s</dd>
                </dl>
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card-head">
            <h2>Tool catalog <span class="badge badge-warning">provisional</span></h2>
            <span class="hint">config/tools.php · LLM guidance + local binaries only. The Scope Enforcer decides what is allowed.</span>
        </div>
        @if ($tools === [])
            <div class="empty">No tools configured. Commands can still be validated, but nothing can be executed.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Tool</th><th>Action</th><th>Target</th><th class="hide-sm">LLM hint</th><th>Binary</th><th>Timeout</th></tr></thead>
                    <tbody>
                    @foreach ($tools as $name => $tool)
                        <tr>
                            <td class="mono">{{ $name }}</td>
                            <td class="mono">{{ $tool['action'] ?? '—' }}</td>
                            <td>{{ $tool['target'] ?? '—' }}</td>
                            <td class="hide-sm cell-muted">{{ $tool['hint'] ?? '—' }}</td>
                            <td class="mono">{{ $tool['binary'] ?? '—' }}</td>
                            <td>{{ isset($tool['timeout']) ? $tool['timeout'].' s' : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
