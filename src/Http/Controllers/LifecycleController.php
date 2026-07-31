<?php

namespace Hexalabs\BillingBridge\Http\Controllers;

use App\Enums\SuspendAction;
use App\Http\Controllers\Controller;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Services\Servers\ServerDeletionService;
use App\Services\Servers\SuspensionService;
use Hexalabs\BillingBridge\Http\Requests\ApplyPlanRequest;
use Hexalabs\BillingBridge\Http\Requests\ServerLifecycleRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class LifecycleController extends Controller
{
    public function __construct(
        private readonly SuspensionService $suspension,
        private readonly ServerDeletionService $deletion,
    ) {
    }

    public function show(ServerLifecycleRequest $request, Server $server): JsonResponse
    {
        $this->assertBillingOwned($server);

        return response()->json([
            'id'          => $server->id,
            'uuid'        => $server->uuid,
            'identifier'  => $server->uuid_short,
            'name'        => $server->name,
            'external_id' => $server->external_id,
            'suspended'   => $server->isSuspended(),
            'status'      => $server->status?->value,
        ]);
    }

    public function suspend(ServerLifecycleRequest $request, Server $server): JsonResponse
    {
        $this->assertBillingOwned($server);
        $this->suspension->handle($server, SuspendAction::Suspend);

        return response()->json(['suspended' => true]);
    }

    public function unsuspend(ServerLifecycleRequest $request, Server $server): JsonResponse
    {
        $this->assertBillingOwned($server);
        $this->suspension->handle($server, SuspendAction::Unsuspend);

        return response()->json(['suspended' => false]);
    }

    public function applyPlan(ApplyPlanRequest $request, Server $server): JsonResponse
    {
        $this->assertBillingOwned($server);

        $limits = $request->validated('limits');

        $server->update([
            'cpu'              => $limits['cpu'],
            'memory'           => $limits['memory'],
            'disk'             => $limits['disk'],
            'swap'             => $limits['swap'],
            'io'               => $limits['io'],
            'allocation_limit' => $limits['allocation_limit'],
            'database_limit'   => $limits['database_limit'],
            'backup_limit'     => $limits['backup_limit'],
        ]);

        $this->applyEnvironment($server, $request->validated('environment'));

        return response()->json(['updated' => true]);
    }

    public function destroy(ServerLifecycleRequest $request, Server $server): JsonResponse
    {
        $this->assertBillingOwned($server);

        $this->deletion->withForce(true)->handle($server);

        return response()->json([], 204);
    }

    /**
     * Write the egg's variables straight onto the server, same as the plugin's
     * applyEnvironmentOverrides did. Going through StartupModificationService
     * would run the variables through user-level validation, which would drop
     * any value the egg marks as not user-editable.
     *
     * @param  array<string, string|null>  $environment
     */
    private function applyEnvironment(Server $server, array $environment): void
    {
        if ($environment === []) {
            return;
        }

        $eggVariables = EggVariable::where('egg_id', $server->egg_id)->get();

        foreach ($eggVariables as $eggVariable) {
            if (!array_key_exists($eggVariable->env_variable, $environment)) {
                continue;
            }

            ServerVariable::updateOrCreate(
                ['server_id' => $server->id, 'variable_id' => $eggVariable->id],
                ['variable_value' => $environment[$eggVariable->env_variable]],
            );
        }
    }

    /**
     * The billing service stamps every server it creates with the order id in
     * `external_id`. Refusing anything without one keeps a compromised billing
     * key from touching servers created by hand in the panel.
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
