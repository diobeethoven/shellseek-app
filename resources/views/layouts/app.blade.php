<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>@yield('title') · ShellSeek</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body @class(['is-chat' => View::hasSection('chat')])>
@php
    $nav = [
        ['route' => 'recon.index', 'match' => 'recon.*', 'label' => 'Recon Workspace', 'icon' => 'M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z'],
        ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z'],
        ['route' => 'history.index', 'match' => 'history.*', 'label' => 'Log', 'icon' => 'M4 5h2v2H4V5zm4 0h12v2H8V5zm-4 6h2v2H4v-2zm4 0h12v2H8v-2zm-4 6h2v2H4v-2zm4 0h12v2H8v-2z'],
    ];
    $more = [
        ['route' => 'validations.index', 'match' => 'validations.*', 'label' => 'Validation Results'],
        ['route' => 'status', 'match' => 'status', 'label' => 'System Status'],
        ['route' => 'settings', 'match' => 'settings*', 'label' => 'Settings'],
    ];
@endphp
<div class="app">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <img class="brand-logo" src="{{ asset('images/shellseek-wordmark.png') }}" alt="shellseek" width="305" height="52">
        </a>

        <nav class="nav" aria-label="Main">
            @foreach ($nav as $item)
                @php $isActive = request()->routeIs($item['match']); @endphp
                <a href="{{ route($item['route']) }}" @class(['active' => $isActive]) @if ($isActive) aria-current="page" @endif data-nav="{{ $item['label'] }}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="sidebar-foot">
            <nav class="foot-links" aria-label="More">
                @foreach ($more as $item)
                    @php $isActive = request()->routeIs($item['match']); @endphp
                    <a href="{{ route($item['route']) }}" @class(['active' => $isActive]) @if ($isActive) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
            @auth
                <div class="user-menu" id="user-menu">
                    <div class="user-id">
                        <span class="user-name">{{ auth()->user()->name }}</span>
                        <span class="user-handle">{{ '@'.auth()->user()->username }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="logout-form">
                        @csrf
                        <button class="btn btn-ghost btn-sm" type="submit">Logout</button>
                    </form>
                </div>
            @endauth
        </div>
    </aside>
    <div class="scrim" data-menu-close aria-hidden="true"></div>

    <div class="main">
        @hasSection('chat')
            @yield('chat')
        @else
            <header class="topbar">
                <button class="menu-toggle" type="button" data-menu-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">☰</button>
                <div>
                    <h1>@yield('title')</h1>
                    @hasSection('subtitle')<div class="subtitle">@yield('subtitle')</div>@endif
                </div>
                <div class="spacer"></div>
                @yield('actions')
            </header>

            <main class="content">
                @if (session('recon'))
                    @include('partials.flash', ['flash' => session('recon')])
                @endif
                @yield('content')
            </main>
        @endif
    </div>
</div>
<script src="{{ asset('js/app.js') }}" defer></script>
</body>
</html>
