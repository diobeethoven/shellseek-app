<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    use RejectsUnknownFields;

    public const INVALID = 'Invalid username or password.';

    public const INACTIVE = 'This account is not activated yet. Ask the lab owner to activate it on VM 1.';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username')))]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['username', 'password'];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username is required.',
            'password.required' => 'Password is required.',
            '*.string' => 'Invalid input.',
            '*.max' => 'Input is too long.',
        ];
    }

    /**
     * Check the credentials and return the user, or throw a validation error.
     * The same message is used for an unknown username and a wrong password,
     * and a password hash is checked in both cases so timing does not reveal
     * whether the username exists. An inactive account is never signed in.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $username = (string) $this->validated('username');
        $password = (string) $this->validated('password');

        $user = User::query()->where('username', $username)->first();
        $valid = $user !== null
            ? Hash::check($password, $user->password)
            : Hash::check($password, self::dummyHash()) && false;

        if (! $valid) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages(['username' => self::INVALID]);
        }

        if (! $user->isActiveAccount()) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages(['username' => self::INACTIVE]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /** @throws ValidationException */
    private function ensureIsNotRateLimited(): void
    {
        $max = max(1, (int) config('auth_app.login_max_attempts', 5));
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $max)) {
            return;
        }

        event(new Lockout($this));
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => 'Too many sign-in attempts. Try again in '.$seconds.' seconds.',
        ]);
    }

    private function throttleKey(): string
    {
        return 'login|'.Str::transliterate(mb_strtolower((string) $this->input('username'))).'|'.$this->ip();
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }
}
