{{-- Structured command proposed by the LLM (untrusted until the Scope Enforcer decides). --}}
@php $cmdline = $log->tool !== null ? trim($log->tool.' '.implode(' ', $log->parameters ?? []).' '.$log->target) : null; @endphp
<details class="tool tool-command" open aria-label="Generated command">
    <summary>
        <span class="tool-chev" aria-hidden="true"></span>
        <span class="tool-name">Generated command</span>
        <span class="tool-arg">{{ $cmdline ?? 'no command' }}</span>
        <span class="tool-meta">{{ $log->llm_model ?? config('llm.model') }}@if ($log->llm_duration_ms) · {{ number_format($log->llm_duration_ms / 1000, 1) }}s @endif</span>
    </summary>
    <div class="tool-body">
        @if ($cmdline !== null)
            <div class="code">
                <div class="code-bar">
                    <span>proposed by the LLM · checked by the Scope Enforcer below</span>
                    <button type="button" class="copy" data-copy="{{ $cmdline }}">Copy</button>
                </div>
                <pre class="cmdline"><span class="prompt" aria-hidden="true">$ </span>{{ $cmdline }}</pre>
            </div>
            <dl class="fields">
                <div><dt>Tool</dt><dd class="mono">{{ $log->tool }}</dd></div>
                <div><dt>Action</dt><dd class="mono">{{ $log->action }}</dd></div>
                <div><dt>Target</dt><dd class="mono">{{ $log->target }}</dd></div>
                <div class="fields-wide"><dt>Parameters</dt>
                    <dd>
                        @forelse ($log->parameters ?? [] as $p)<code class="param">{{ $p }}</code>@empty<span class="faint">none</span>@endforelse
                        <span class="mono faint params-json">{{ json_encode($log->parameters ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</span>
                    </dd>
                </div>
            </dl>
        @else
            <p class="muted">{{ $log->parse_detail ?? ($log->llm_failure ? 'LLM failure: '.$log->llm_failure : 'No command.') }}</p>
            @if ($log->llm_output)
                <div class="code">
                    <div class="code-bar"><span>raw LLM output</span></div>
                    <pre class="output">{{ $log->llm_output }}</pre>
                </div>
            @endif
        @endif
    </div>
</details>
