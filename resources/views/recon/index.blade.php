@extends('layouts.app')

@section('title', 'Recon Workspace')

@section('chat')
    {{-- Start screen: logo + centred composer. app.js drops .is-home on submit so the
         pending request is shown in the normal conversation layout. --}}
    <div class="recon-home is-home" data-home>
        <x-chat-header heading="Recon Workspace" meta="natural-language footprinting & scanning" />

        <div class="chat-scroll" data-chat-scroll>
            <div class="thread" data-thread>
                @if (session('recon'))
                    @include('partials.flash', ['flash' => session('recon')])
                @endif

                <section class="welcome" data-welcome>
                    <img class="welcome-logo" src="{{ asset('images/shellseek-logo.png') }}" alt="shellseek" width="948" height="273">
                </section>
            </div>
        </div>

        @include('recon.partials.composer', ['autofocus' => true, 'home' => true])

        <footer class="home-foot">
            <span>scope enforcer {{ parse_url(config('scope_enforcer.base_url'), PHP_URL_HOST) }} · fail-closed</span>
            <span class="hide-sm">footprinting &amp; scanning only · approved lab targets</span>
        </footer>
    </div>
@endsection
