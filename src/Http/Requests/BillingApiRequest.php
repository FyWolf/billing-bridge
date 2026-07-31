<?php

namespace Hexalabs\BillingBridge\Http\Requests;

use App\Http\Requests\Api\Application\ApplicationApiRequest;
use App\Services\Acl\Api\AdminAcl;
use Hexalabs\BillingBridge\Providers\BillingBridgeProvider;

/**
 * Base request for every bridge endpoint.
 *
 * Gating on our own `billing` resource means the billing service's key never
 * needs `server: write` or `user: write`.
 */
abstract class BillingApiRequest extends ApplicationApiRequest
{
    protected ?string $resource = BillingBridgeProvider::RESOURCE_NAME;

    protected int $permission = AdminAcl::WRITE;
}
