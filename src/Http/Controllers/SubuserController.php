<?php

namespace Hexalabs\BillingBridge\Http\Controllers;

use App\Enums\SubuserPermission;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Hexalabs\BillingBridge\Http\Requests\ServerLifecycleRequest;
use Hexalabs\BillingBridge\Http\Requests\StoreSubuserRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Server collaborators, granted from the storefront.
 *
 * A customer can share a server with someone else. Doing that panel-side made
 * an account the storefront knew nothing about, so the two sides disagreed
 * about who could reach a server — and the storefront, which owns the
 * commercial relationship, was the one in the dark.
 *
 * So the storefront now owns the invitation and this is how it lands. The
 * counterpart is {@see \Hexalabs\BillingBridge\Providers\BillingBridgeProvider}
 * removing the panel's own add/remove subuser controls for billing-owned
 * servers: one writer, no reconciliation.
 *
 * Permissions arrive as the panel's own `SubuserPermission` values and are
 * filtered against that enum rather than trusted — the storefront is a separate
 * deployment and can be a release behind, so a permission it knows about and
 * this panel does not must be dropped rather than written.
 */
class SubuserController extends Controller
{
    /**
     * Add a collaborator, or replace the permissions of one already there.
     *
     * Idempotent by (server, user): the storefront retries a request it never
     * got a response to, and re-sending must not fail on a unique constraint.
     */
    public function store(StoreSubuserRequest $request, Server $server): JsonResponse
    {
        $this->assertBillingOwned($server);

        $user = User::find($request->validated('user_id'));

        if (! $user) {
            throw new AccessDeniedHttpException('No such panel user.');
        }

        // The owner already has everything; a subuser row for them would be a
        // second, weaker source of truth about their own server.
        if ($server->owner_id === $user->id) {
            throw new AccessDeniedHttpException('The server owner cannot be added as a collaborator.');
        }

        $permissions = $this->cleanPermissions($request->validated('permissions', []));

        $subuser = Subuser::updateOrCreate(
            ['server_id' => $server->id, 'user_id' => $user->id],
            ['permissions' => $permissions],
        );

        return response()->json([
            'subuser_id'  => $subuser->id,
            'user_id'     => $user->id,
            'permissions' => $subuser->permissions,
        ], $subuser->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Remove a collaborator.
     *
     * Already gone is a success: revocation is retried from the storefront on
     * termination and on account deletion, and both must be safe to repeat.
     */
    public function destroy(ServerLifecycleRequest $request, Server $server, int $user): JsonResponse
    {
        $this->assertBillingOwned($server);

        Subuser::where('server_id', $server->id)->where('user_id', $user)->delete();

        return response()->json(['status' => 'removed']);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    private function cleanPermissions(array $permissions): array
    {
        $known = collect($permissions)
            ->filter(fn ($permission) => is_string($permission) && SubuserPermission::tryFrom($permission) !== null)
            ->values();

        // Without this the collaborator holds permissions they can never use:
        // every server view opens over the websocket first, so the panel treats
        // its absence as no access at all.
        return $known
            ->push(SubuserPermission::WebsocketConnect->value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Same rule as LifecycleController: a server billing did not create is not
     * billing's to share out. Keeps a compromised billing key from handing
     * someone access to a server built by hand in the panel.
     */
    private function assertBillingOwned(Server $server): void
    {
        if (blank($server->external_id)) {
            throw new AccessDeniedHttpException(
                "Server #{$server->id} was not created by billing and cannot be managed through this endpoint."
            );
        }
    }
}
