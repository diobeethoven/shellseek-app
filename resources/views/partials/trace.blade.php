{{-- Pipeline trace for one request: LLM -> structure -> Scope Enforcer -> execution. Display only. --}}
@php
    $outcome = \App\Services\History\Outcome::tryFrom((string) $log->outcome);
    $llmTone = $log->llm_failure ? 'deny' : (($log->tool !== null || $log->llm_output) ? 'ok' : 'idle');
    $parseTone = $log->tool !== null ? 'ok' : ($log->parse_detail ? 'deny' : 'idle');
    $seTone = match (true) {
        $log->decision === null => 'idle',
        $log->origin === 'fail_closed' => 'deny',
        $log->decision === 'ALLOW' => 'ok',
        $log->decision === 'WARNING' => 'warn',
        default => 'deny',
    };
    $execTone = match (true) {
        $log->execution_status !== null => $outcome?->tone() ?? 'neutral',
        $log->gate_refusal !== null => 'deny',
        default => 'idle',
    };
    $steps = [
        ['LLM', $llmTone, $log->llm_failure ? 'failed' : ($llmTone === 'ok' ? 'proposed' : 'skipped')],
        ['Structure', $parseTone, $parseTone === 'ok' ? 'valid shape' : ($parseTone === 'deny' ? 'invalid' : 'skipped')],
        ['Scope Enforcer', $seTone, $log->decision ? ($log->origin === 'fail_closed' ? 'DENY (fail-closed)' : $log->decision) : 'not sent'],
        ['Execution', $execTone, $log->execution_status ?? ($log->gate_refusal ? 'gate refused' : 'not executed')],
    ];
@endphp
<ol class="trace" aria-label="Pipeline">
    @foreach ($steps as [$label, $tone, $state])
        <li class="trace-step tone-{{ $tone }}">
            <span class="dot dot-sm {{ $tone }}" aria-hidden="true"></span>
            <span class="trace-label">{{ $label }}</span>
            <span class="trace-state">{{ $state }}</span>
        </li>
    @endforeach
</ol>
