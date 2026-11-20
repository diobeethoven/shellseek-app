@extends('layouts.auth')

@section('title', 'Sign in')

@section('content')
    <form method="POST" action="{{ route('login.store') }}" class="auth-form" novalidate>
        @csrf
        <div class="auth-field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="64" required autofocus>
            @error('username')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>
        <div class="auth-field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            @error('password')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>
        <button class="btn auth-submit" type="submit">Sign in</button>
    </form>
    @if ($registrationEnabled)
        <p class="auth-alt">No account yet? <a href="{{ route('register') }}">Create one</a></p>
    @endif
@endsection
