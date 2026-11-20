<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>@yield('title') · ShellSeek</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-page">
<main class="auth-wrap">
    <section class="auth-card" aria-labelledby="auth-title">
        <img class="auth-logo" src="{{ asset('images/shellseek-wordmark.png') }}" alt="shellseek" width="305" height="52">
        <h1 id="auth-title">@yield('title')</h1>

        @if (session('status'))
            <div class="alert tone-ok" role="status" id="auth-status"><div>{{ session('status') }}</div></div>
        @endif
        @error('form')
            <div class="alert tone-deny" role="alert"><div>{{ $message }}</div></div>
        @enderror

        @yield('content')
    </section>
    <p class="auth-foot">Login only controls access to this app. Every command is still checked by the Scope Enforcer.</p>
</main>
</body>
</html>
