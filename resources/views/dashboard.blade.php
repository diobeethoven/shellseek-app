@extends('layouts.app')

@section('title', 'Dashboard')

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('dashboard', ['refresh' => 1]) }}">refresh</a>
@endsection

@section('content')
    @php
        $se = $status->scopeEnforcer;
        $llm = $status->llm;
        $decided = $allow + $warning + $deny;
        $pct = fn (int $n) => $decided > 0 ? round($n * 100 / $decided, 1) : 0;
    @endphp

    @unless ($se->healthy)
        <div class="alert tone-deny" role="status">
            <span class="alert-icon">!</span>
            <div>
                <strong>Scope Enforcer unavailable – all requests are treated as DENY.</strong>
                <span class="prose">{{ $se->message }}</span>
            </div>
        </div>
    @endunless

    <section class="section" aria-labelledby="h-status">
        <h2 class="section-title" id="h-status">status</h2>
        <dl class="status-list">
            <div>
                <dt>scope enforcer</dt>
                <dd><span class="dot {{ $se->healthy ? 'ok' : 'deny' }}" aria-hidden="true"></span>
                    <span id="dash-se">{{ $se->healthy ? 'healthy' : ($se->reachable ? 'unhealthy' : 'unreachable') }}</span>@unless ($se->healthy)<span class="deny-text"> – treated as DENY</span>@endunless</dd>
                <dd class="faint">{{ config('scope_enforcer.base_url') }}@if ($se->latencyMs !== null) · {{ $se->latencyMs }} ms @endif</dd>
            </div>
            <div>
                <dt>local llm</dt>
                <dd><span class="dot {{ $llm->reachable && $llm->modelAvailable ? 'ok' : 'warn' }}" aria-hidden="true"></span>
                    <span id="dash-llm">{{ $llm->reachable ? ($llm->modelAvailable ? 'ready' : 'model missing') : 'unreachable' }}</span></dd>
                <dd class="faint">{{ $llm->model }}</dd>
            </div>
            <div>
                <dt>execution</dt>
                <dd><span class="dot {{ $status->executionPossible() ? 'ok' : 'warn' }}" aria-hidden="true"></span>
                    {{ $status->executionPossible() ? 'enabled' : 'disabled' }}</dd>
                <dd class="faint">{{ $status->signatureVerificationAvailable ? 'signed, unexpired ALLOW required' : 'signature verification not configured' }}</dd>
            </div>
            <div>
                <dt>mode</dt>
                <dd><span class="dot ok" aria-hidden="true"></span>fail-closed</dd>
                <dd class="faint">anything unconfirmed is DENY</dd>
            </div>
        </dl>
        <p class="section-foot">checked {{ date('H:i:s', $status->checkedAt) }} · cached up to 15 s · <a href="{{ route('status') }}">details</a></p>
    </section>

    <section class="section" aria-labelledby="h-requests">
        <h2 class="section-title" id="h-requests">requests</h2>
        <p class="counts">
            <span><b data-stat="total">{{ $total }}</b> total</span>
            <span class="c-ok"><b data-stat="allow">{{ $allow }}</b> allow</span>
            <span class="c-warn"><b data-stat="warning">{{ $warning }}</b> warning</span>
            <span class="c-deny"><b data-stat="deny">{{ $deny }}</b> deny <span class="faint">({{ $failClosed }} fail-closed)</span></span>
            <span><b data-stat="executed">{{ $executed }}</b> executed</span>
        </p>
        @if ($decided > 0)
            <div class="ratio" role="img" aria-label="{{ $allow }} allow, {{ $warning }} warning, {{ $deny }} deny">
                <span class="r-ok" style="width: {{ $pct($allow) }}%"></span>
                <span class="r-warn" style="width: {{ $pct($warning) }}%"></span>
                <span class="r-deny" style="width: {{ $pct($deny) }}%"></span>
            </div>
        @endif
        <p class="section-foot prose">WARNING is held and never executed. DENY includes every failure ShellSeek could not confirm (fail-closed).</p>
    </section>

    <section class="section" aria-labelledby="h-recent">
        <div class="section-head">
            <h2 class="section-title" id="h-recent">recent</h2>
            <a href="{{ route('history.index') }}">open log →</a>
        </div>
        @if ($recent->isEmpty())
            <div class="empty">No requests yet. Start one from <a href="{{ route('recon.index') }}">Recon Workspace</a>.</div>
        @else
            <ol class="log">
                @foreach ($recent as $log)
                    @include('partials.log-entry')
                @endforeach
            </ol>
        @endif
    </section>
@endsection
