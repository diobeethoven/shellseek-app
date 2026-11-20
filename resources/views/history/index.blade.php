@extends('layouts.app')

@section('title', 'Log')
@section('subtitle', 'Every request, including failures, as recorded before each step')

@section('content')
    @if (session('history'))
        @include('partials.flash', ['flash' => session('history')])
    @endif

    <section class="panel">
        <div class="panel-head">
            <div class="filters" aria-label="Active or archived entries">
                <a @class(['chip', 'active' => ! $archived]) href="{{ route('history.index') }}">Active</a>
                <a @class(['chip', 'active' => $archived]) href="{{ route('history.index', ['view' => 'archived']) }}">Archived ({{ $archivedCount }})</a>
            </div>
            <div class="filters" aria-label="Filter by decision">
                <a @class(['chip', 'active' => $decision === null]) href="{{ route('history.index', array_filter(['outcome' => $outcome?->value, 'view' => $archived ? 'archived' : null])) }}">All</a>
                @foreach (['ALLOW', 'WARNING', 'DENY'] as $d)
                    <a @class(['chip', 'active' => $decision?->value === $d]) href="{{ route('history.index', array_filter(['decision' => $d, 'outcome' => $outcome?->value, 'view' => $archived ? 'archived' : null])) }}">{{ $d }}</a>
                @endforeach
            </div>
            <form method="GET" action="{{ route('history.index') }}" class="row">
                @if ($decision)<input type="hidden" name="decision" value="{{ $decision->value }}">@endif
                @if ($archived)<input type="hidden" name="view" value="archived">@endif
                <label for="outcome" class="sr-only">Outcome</label>
                <select id="outcome" name="outcome" data-autosubmit>
                    <option value="">All outcomes</option>
                    @foreach ($outcomes as $o)
                        <option value="{{ $o->value }}" @selected($outcome === $o)>{{ $o->label() }}</option>
                    @endforeach
                </select>
                <noscript><button class="btn btn-ghost btn-sm" type="submit">Filter</button></noscript>
            </form>
            <span class="hint">{{ $logs->total() }} entr{{ $logs->total() === 1 ? 'y' : 'ies' }}</span>
        </div>

        @if ($archived)
            <div class="note">Archived entries are hidden from the Log, Dashboard and Validation Results. Restore brings them back; Delete removes them permanently.</div>
        @endif

        @if ($logs->isEmpty())
            <div class="empty">{{ $archived ? 'No archived entries' : 'No activity recorded' }}{{ ($outcome || $decision) ? ' for this filter' : '' }}.</div>
        @else
            <ol class="log" id="log">
                @foreach ($logs as $log)
                    @include('partials.log-entry')
                @endforeach
            </ol>
            @include('partials.pagination', ['paginator' => $logs])
        @endif
    </section>
@endsection
