<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            // max 72: bcrypt only uses the first 72 bytes
            'password' => ['required', 'string', 'confirmed', Password::min(12)->max(72)->letters()->numbers()],
        ];
    }

    protected function allowedFields(): array
    {
        return ['name', 'username', 'password', 'password_confirmation'];
    }

    public function attributes(): array
    {
        return ['name' => 'name', 'username' => 'username', 'password' => 'password'];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.max' => 'Name may not be longer than 100 characters.',
            'username.required' => 'Username is required.',
            'username.min' => 'Username must be at least 3 characters.',
            'username.max' => 'Username may not be longer than 32 characters.',
            'username.regex' => 'Username may only contain lowercase letters, numbers, dots, underscores and hyphens.',
            'username.unique' => 'This username is already taken.',
            'password.required' => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.min' => 'Password must be at least 12 characters.',
            'password.max' => 'Password may not be longer than 72 characters.',
            'password.password.letters' => 'Password must contain at least one letter.',
            'password.password.numbers' => 'Password must contain at least one number.',
            '*.string' => 'The :attribute is invalid.',
        ];
    }
}
