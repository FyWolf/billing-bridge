<?php

namespace Hexalabs\BillingBridge\Http\Requests;

class ProvisionServerRequest extends BillingApiRequest
{
    public function rules(): array
    {
        return [
            'server'                     => ['required', 'array'],
            'server.name'                => ['required', 'string', 'max:255'],
            'server.external_id'         => ['required', 'string', 'max:255'],
            'server.owner_id'            => ['required', 'integer', 'exists:users,id'],
            'server.egg_id'              => ['required', 'integer', 'exists:eggs,id'],
            'server.environment'         => ['present', 'array'],
            'server.cpu'                 => ['required', 'integer', 'min:0'],
            'server.memory'              => ['required', 'integer', 'min:0'],
            'server.disk'                => ['required', 'integer', 'min:0'],
            'server.swap'                => ['required', 'integer'],
            'server.io'                  => ['required', 'integer', 'min:10', 'max:1000'],
            'server.allocation_limit'    => ['required', 'integer', 'min:0'],
            'server.database_limit'      => ['required', 'integer', 'min:0'],
            'server.backup_limit'        => ['required', 'integer', 'min:0'],
            'server.start_on_completion' => ['sometimes', 'boolean'],
            'server.skip_scripts'        => ['sometimes', 'boolean'],
            'server.oom_killer'          => ['sometimes', 'boolean'],

            'placement'            => ['required', 'array'],
            'placement.node_ids'   => ['present', 'array'],
            'placement.node_ids.*' => ['integer'],
            'placement.ports'      => ['present', 'array'],
            'placement.tags'       => ['present', 'array'],
            'placement.tags.*'     => ['string'],
        ];
    }
}
