{{-- Output of an authorized execution (only present if ExecutionService ran). --}}
@if ($log->execution_status)
    <details class="tool terminal" open aria-label="Terminal output">
        <summary>
            <span class="tool-chev" aria-hidden="true"></span>
            <span class="tool-name">Terminal</span>
            <span class="tool-arg">{{ $log->argv ? implode(' ', $log->argv) : '' }}</span>
            <span class="tool-meta">{{ $log->execution_status }} · exit {{ $log->exit_code ?? 'n/a' }}@if ($log->execution_duration_ms) · {{ number_format($log->execution_duration_ms / 1000, 1) }}s @endif</span>
        </summary>
        <div class="tool-body terminal-body">
            @if ($log->argv)<pre class="terminal-cmd"><span class="prompt" aria-hidden="true">$ </span>{{ implode(' ', $log->argv) }}</pre>@endif
            @if ($log->execution_reason)<p class="terminal-note">{{ $log->execution_reason }}</p>@endif
            <pre class="terminal-out">{{ $log->stdout ?: '(no output)' }}</pre>
            @if ($log->stderr)<pre class="terminal-out terminal-err">{{ $log->stderr }}</pre>@endif
        </div>
    </details>
@endif
