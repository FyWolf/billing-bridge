<?php

namespace Hexalabs\BillingBridge\Http\Requests;

class UpdateUserRequest extends BillingApiRequest
{
    public function rules(): array
    {
        return [
            'email'      => ['sometimes', 'email', 'max:255'],
            'username'   => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
