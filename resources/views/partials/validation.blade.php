{{-- Scope Enforcer decision. Styled as a system verdict, distinct from LLM output. --}}
@php
    $tone = match (true) {
        $log->decision === null => 'neutral',
        $log->origin === 'fail_closed' => 'deny',
        $log->decision === 'ALLOW' => 'ok',
        $log->decision === 'WARNING' => 'warn',
        default => 'deny',
    };
@endphp
<details class="tool verdict tone-{{ $tone }}" open id="validation" aria-label="Scope validation">
    <summary>
        <span class="tool-chev" aria-hidden="true"></span>
        <span class="tool-name">Scope Enforcer</span>
        @if ($log->decision)
            <x-decision-badge :decision="$log->decision" :origin="$log->origin" />
            <span class="risk risk-{{ $log->risk_level ?? 'none' }}" title="Risk level">risk {{ $log->risk_level ?? 'n/a' }}</span>
        @else
            <span class="tool-arg">not sent</span>
        @endif
        <span class="tool-meta">@if ($log->policy_version)policy {{ $log->policy_version }}@endif</span>
    </summary>
    <div class="tool-body">
        @if ($log->decision)
            <dl class="kv">
                <dt>Target checked</dt><dd class="mono">{{ $log->target ?? 'n/a' }}</dd>
                <dt>Execution allowed</dt><dd>{{ $log->execution_allowed ? 'yes' : 'no' }}</dd>
                <dt>Policy version</dt><dd>{{ $log->policy_version ?? 'n/a' }}</dd>
                <dt>Source</dt><dd>{{ $log->origin === 'fail_closed' ? 'ShellSeek fail-closed ('.$log->failure_code.')' : 'Scope Enforcer' }}</dd>
                <dt>Request ID</dt><dd class="mono">{{ $log->request_id ?? 'n/a' }}</dd>
                @if ($log->authorization_expires_at)
                    <dt>Authorization</dt><dd class="mono">valid {{ $log->authorization_expires_at - $log->authorization_issued_at }} s (until {{ date('H:i:s', $log->authorization_expires_at) }})</dd>
                @endif
                @if ($log->gate_refusal)
                    <dt>Execution gate</dt><dd>{{ \App\Services\Execution\GateRefusal::tryFrom($log->gate_refusal)?->message() ?? $log->gate_refusal }}</dd>
                @endif
            </dl>

            <div class="verdict-section">
                <h3>Explanation</h3>
                <ul class="reasons">
                    @forelse ($log->reasons ?? [] as $reason)<li>{{ $reason }}</li>@empty<li class="muted">No reasons given.</li>@endforelse
                </ul>
            </div>

            <div class="verdict-section">
                <h3>Checks</h3>
                <x-validation-flags :flags="$log->validation" />
            </div>

            @if ($log->revalidated)
                <p class="note">The authorization had expired before execution; the identical command was validated again (original {{ $log->original_request_id }}).</p>
            @endif
            @if ($log->decision === 'WARNING')
                <p class="note tone-warn">Execution is held. Human approval for WARNING is {{ $warningApprovalEnabled ? 'enabled' : 'not available in this version' }}.</p>
            @endif
        @else
            <p class="muted">Not sent to the Scope Enforcer.</p>
        @endif
    </div>
</details>
