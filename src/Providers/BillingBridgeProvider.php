<?php

namespace Hexalabs\BillingBridge\Providers;

use App\Extensions\OAuth\OAuthService;
use App\Models\ApiKey;
use App\Models\Server;
use Hexalabs\BillingBridge\OAuth\HexalabsSchema;
use Illuminate\Support\Facades\Gate;
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

        $this->lockManagedSubusers();
    }

    /**
     * Withdraw the panel's own add/remove-collaborator controls on servers
     * billing owns.
     *
     * Collaborators are invited from the storefront, which records who was
     * invited, by whom, and with which permissions, and revokes them when the
     * order ends. A subuser added panel-side is invisible to all of that: the
     * storefront cannot revoke it, cannot show it to the owner, and will not
     * remove it when the server is terminated. Two writers, no reconciliation.
     *
     * So there is one writer. This is a `Gate::before`, which runs ahead of
     * `ServerPolicy::before()` and therefore overrides even the owner's blanket
     * `true` — the owner is precisely who would otherwise be able to do it.
     *
     * It removes the UI as a side effect rather than by hiding anything:
     * `SubuserResource` already gates its create, edit and delete actions on
     * these three abilities, so denying them takes the buttons away *and* blocks
     * the underlying action. No core resource is overridden, so a panel upgrade
     * that reworks that screen cannot break this.
     *
     * **Read permissions are untouched.** An owner can still see who has access
     * to their server on the panel; they simply cannot change it there. Being
     * unable to see it would be worse than the drift this prevents.
     *
     * "Billing owns it" means `external_id` is set — the same rule
     * `LifecycleController::assertBillingOwned()` uses, with the same known
     * limitation: a server created by hand that happens to carry an
     * `external_id` is treated as billing's.
     */
    private function lockManagedSubusers(): void
    {
        $managed = [
            'user.create',
            'user.update',
            'user.delete',
        ];

        Gate::before(function ($user, string $ability, array $arguments = []) use ($managed) {
            if (! in_array($ability, $managed, true)) {
                return null;
            }

            $server = $arguments[0] ?? null;

            if (! $server instanceof Server || blank($server->external_id)) {
                return null;
            }

            // A hard deny. Returning null here would let the owner's `true` in
            // ServerPolicy::before() stand, which is the whole thing being
            // prevented.
            return false;
        });
    }
}
