<?php

namespace Hexalabs\BillingBridge\Http\Requests;

class StoreUserRequest extends BillingApiRequest
{
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:255'],
            'email'       => ['required', 'email', 'max:255'],
            'username'    => ['required', 'string', 'max:255'],
            'first_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name'   => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
