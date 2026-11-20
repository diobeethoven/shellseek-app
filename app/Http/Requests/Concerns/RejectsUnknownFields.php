<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Rejects any submitted field that the form does not define (e.g. "role" or
 * "is_active" on the registration form) instead of silently ignoring it.
 */
trait RejectsUnknownFields
{
    /** @return list<string> */
    abstract protected function allowedFields(): array;

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $unknown = array_diff(array_keys($this->all()), [...$this->allowedFields(), '_token']);
            if ($unknown !== []) {
                $validator->errors()->add('form', 'The form contains unknown fields.');
            }
        });
    }
}
