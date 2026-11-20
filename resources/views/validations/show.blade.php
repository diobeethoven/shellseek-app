@extends('layouts.app')

@section('title', 'Request '.($log->request_id ?? '#'.$log->id))
@section('subtitle', $log->created_at?->format('Y-m-d H:i:s'))

@section('actions')
    <a class="btn btn-ghost" href="{{ route('recon.show', $log) }}">Open in Recon Workspace</a>
    <a class="btn btn-ghost" href="{{ route('validations.index') }}">← Back</a>
@endsection

@section('content')
    <div class="row">
        <x-decision-badge :decision="$log->decision" :origin="$log->origin" />
        <x-outcome-badge :outcome="$log->outcome" />
        @if ($log->revalidated)<span class="badge badge-neutral">Re-validated (original {{ $log->original_request_id }})</span>@endif
    </div>

    <section class="card">
        <div class="card-head"><h2>Instruction</h2></div>
        <div class="card-body"><p style="margin:0">{{ $log->instruction }}</p></div>
    </section>

    @include('partials.trace')

    <div class="stack">
        @include('partials.command')
        @include('partials.validation')
    </div>

    @include('partials.terminal')
@endsection
