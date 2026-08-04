<?php

namespace Hexalabs\BillingBridge\Http\Requests;

class StoreSubuserRequest extends BillingApiRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            // Validated only as strings here; the controller filters them
            // against the panel's own SubuserPermission enum. A rule listing the
            // cases would reject the whole request when the storefront is one
            // release ahead, rather than dropping the one permission this panel
            // does not have yet.
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'max:64'],
        ];
    }
}
