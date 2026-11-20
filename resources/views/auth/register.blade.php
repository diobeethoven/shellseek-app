@extends('layouts.auth')

@section('title', 'Create account')

@section('content')
    <form method="POST" action="{{ route('register.store') }}" class="auth-form" novalidate>
        @csrf
        <div class="auth-field">
            <label for="name">Name</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="100" required autofocus>
            @error('name')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>
        <div class="auth-field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" minlength="3" maxlength="32" pattern="[a-z0-9._-]+" required>
            <p class="field-help">3–32 characters: lowercase letters, numbers, dots, underscores, hyphens.</p>
            @error('username')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>
        <div class="auth-field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            <p class="field-help">At least 12 characters, with letters and numbers.</p>
            @error('password')@foreach ($errors->get('password') as $msg)<p class="field-error" role="alert">{{ $msg }}</p>@endforeach @enderror
        </div>
        <div class="auth-field">
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        </div>
        <button class="btn auth-submit" type="submit">Create account</button>
    </form>
    <p class="auth-alt">New accounts must be activated by the lab owner on VM 1 before they can be used. Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
