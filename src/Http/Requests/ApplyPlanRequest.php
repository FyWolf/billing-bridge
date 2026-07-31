<?php

namespace Hexalabs\BillingBridge\Http\Requests;

class ApplyPlanRequest extends BillingApiRequest
{
    public function rules(): array
    {
        return [
            'limits'                  => ['required', 'array'],
            'limits.cpu'              => ['required', 'integer', 'min:0'],
            'limits.memory'           => ['required', 'integer', 'min:0'],
            'limits.disk'             => ['required', 'integer', 'min:0'],
            'limits.swap'             => ['required', 'integer'],
            'limits.io'               => ['required', 'integer', 'min:10', 'max:1000'],
            'limits.allocation_limit' => ['required', 'integer', 'min:0'],
            'limits.database_limit'   => ['required', 'integer', 'min:0'],
            'limits.backup_limit'     => ['required', 'integer', 'min:0'],

            'environment' => ['present', 'array'],
        ];
    }
}
