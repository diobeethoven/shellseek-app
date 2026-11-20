<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReconRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'instruction' => ['required', 'string', 'max:1000'],
        ];
    }
}
