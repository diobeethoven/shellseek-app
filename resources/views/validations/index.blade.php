@extends('layouts.app')

@section('title', 'Validation Results')
@section('subtitle', 'Decisions returned by the Scope Enforcer')

@section('content')
    <section class="card">
        <div class="card-head">
            <div class="filters" aria-label="Filter by decision">
                <a @class(['chip', 'active' => $decision === null]) href="{{ route('validations.index') }}">All</a>
                @foreach (['ALLOW', 'WARNING', 'DENY'] as $d)
                    <a @class(['chip', 'active' => $decision?->value === $d]) href="{{ route('validations.index', ['decision' => $d]) }}">{{ $d }}</a>
                @endforeach
            </div>
            <span class="hint">{{ $logs->total() }} result(s)</span>
        </div>
        @if ($logs->isEmpty())
            <div class="empty">No validation results yet.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Request</th><th>Command</th><th>Decision</th><th>Risk</th><th class="hide-sm">Reasons</th><th class="hide-sm">Time</th></tr></thead>
                    <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td><a class="mono" href="{{ route('validations.show', $log) }}">{{ $log->request_id ?? '#'.$log->id }}</a></td>
                            <td class="mono truncate">{{ $log->tool }} {{ implode(' ', $log->parameters ?? []) }} {{ $log->target }}</td>
                            <td><x-decision-badge :decision="$log->decision" :origin="$log->origin" /></td>
                            <td>@if ($log->risk_level)<span class="risk risk-{{ $log->risk_level }}">{{ $log->risk_level }}</span>@else<span class="faint">n/a</span>@endif</td>
                            <td class="hide-sm truncate">{{ implode('; ', $log->reasons ?? []) ?: '—' }}</td>
                            <td class="hide-sm cell-muted mono">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('partials.pagination', ['paginator' => $logs])
        @endif
    </section>
@endsection
