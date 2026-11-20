{{-- One activity row (Log page and Dashboard "recent"): prompt, command, decision, execution status.
     With $manage (Log page only) it also shows Archive/Restore and Delete. --}}
@php
    $exec = match (true) {
        $log->execution_status !== null => $log->execution_status.' · exit '.($log->exit_code ?? 'n/a'),
        $log->decision === 'WARNING' => 'held – not executed',
        $log->gate_refusal !== null => 'gate refused – not executed',
        default => 'not executed',
    };
@endphp
<li class="log-entry">
    <time class="log-time" datetime="{{ $log->created_at?->toIso8601String() }}">{{ $log->created_at?->format('Y-m-d H:i:s') }}</time>
    <div class="log-main">
        <a class="log-prompt" href="{{ route('recon.show', $log) }}">{{ $log->instruction }}</a>
        <div class="log-cmd">
            @if ($log->tool)
                <span class="prompt" aria-hidden="true">$ </span>{{ trim($log->tool.' '.implode(' ', $log->parameters ?? []).' '.$log->target) }}
            @else
                <span class="faint">no command generated</span>
            @endif
        </div>
        @if (($log->reasons ?? []) !== [])
            <div class="log-reason">{{ implode('; ', $log->reasons) }}</div>
        @endif
    </div>
    <div class="log-status">
        <x-decision-badge :decision="$log->decision" :origin="$log->origin" />
        <x-outcome-badge :outcome="$log->outcome" />
        <span class="log-exec">{{ $exec }}</span>
        @if (! empty($manage))
            <div class="log-actions">
                @if ($log->isManageable())
                    @if ($log->isArchived())
                        <form method="POST" action="{{ route('history.restore', $log) }}">
                            @csrf
                            <button class="btn btn-ghost btn-sm" type="submit">Restore</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('history.archive', $log) }}">
                            @csrf
                            <button class="btn btn-ghost btn-sm" type="submit">Archive</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('history.destroy', $log) }}" data-confirm="Delete entry #{{ $log->id }} permanently? This cannot be undone.">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-ghost btn-sm btn-danger" type="submit">Delete</button>
                    </form>
                @else
                    <span class="log-locked">running – locked</span>
                @endif
            </div>
        @endif
    </div>
</li>
