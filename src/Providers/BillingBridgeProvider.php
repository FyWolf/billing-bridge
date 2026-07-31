<?php

namespace Hexalabs\BillingBridge\Providers;

use App\Extensions\OAuth\OAuthService;
use App\Models\ApiKey;
use Hexalabs\BillingBridge\OAuth\HexalabsSchema;
use Illuminate\Support\ServiceProvider;

class BillingBridgeProvider extends ServiceProvider
{
    /**
     * The ACL resource this plugin's endpoints are gated on.
     *
     * Registering our own resource is what lets the billing service hold a
     * `papp_` key scoped to `billing: write` and nothing else. Using the core
     * server/user endpoints instead would require `server: write`, which grants
     * delete rights over every server on the panel.
     */
    public const RESOURCE_NAME = 'billing';

    public function register(): void
    {
        ApiKey::registerCustomResourceName(self::RESOURCE_NAME);
    }

    public function boot(): void
    {
        // Lets panel users sign in with their billing account. Configured from
        // the panel's admin settings; inert until OAUTH_HEXALABS_ENABLED is set.
        $this->app->make(OAuthService::class)->register(new HexalabsSchema());
    }
}
