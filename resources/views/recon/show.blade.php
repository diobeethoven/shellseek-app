@extends('layouts.app')

@section('title', \Illuminate\Support\Str::limit($log->instruction, 60))

@section('chat')
    <x-chat-header :heading="\Illuminate\Support\Str::limit($log->instruction, 90)"
                   :meta="($log->request_id ?? '#'.$log->id).' · '.$log->created_at?->format('Y-m-d H:i:s')">
        <x-decision-badge :decision="$log->decision" :origin="$log->origin" class="hide-sm" />
        <a class="btn btn-ghost btn-sm" href="{{ route('validations.show', $log) }}">Technical details</a>
    </x-chat-header>

    <div class="chat-scroll" data-chat-scroll>
        <div class="thread" data-thread>
            <article class="msg msg-user">
                <div class="msg-meta"><span class="msg-who">You</span><time>{{ $log->created_at?->format('H:i') }}</time></div>
                <div class="msg-body">{{ $log->instruction }}</div>
            </article>

            <article class="msg msg-assistant">
                <div class="msg-meta">
                    <span class="msg-avatar" aria-hidden="true">S</span>
                    <span class="msg-who">ShellSeek</span>
                    <x-outcome-badge :outcome="$log->outcome" />
                    @if ($log->revalidated)<span class="badge badge-neutral">Re-validated</span>@endif
                </div>

                @include('partials.trace')

                @if (session('recon'))
                    @include('partials.flash', ['flash' => session('recon')])
                @endif

                @include('partials.command')
                @include('partials.validation')
                @include('partials.terminal')
            </article>
        </div>
    </div>

    @include('recon.partials.composer')
@endsection
