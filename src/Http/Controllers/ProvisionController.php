<?php

namespace Hexalabs\BillingBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Servers\ServerCreationService;
use Hexalabs\BillingBridge\Http\Requests\ProvisionServerRequest;
use Hexalabs\BillingBridge\Services\DeploymentPlanner;
use Illuminate\Http\JsonResponse;

class ProvisionController extends Controller
{
    public function __construct(
        private readonly ServerCreationService $creation,
        private readonly DeploymentPlanner $planner,
    ) {
    }

    public function __invoke(ProvisionServerRequest $request): JsonResponse
    {
        $spec      = $request->validated('server');
        $placement = $request->validated('placement');

        // Idempotency: the billing service retries CreateServerJob up to three
        // times, and a timeout on a request that actually succeeded would
        // otherwise create a second server for the same order.
        $existing = Server::where('external_id', $spec['external_id'])->first();

        if ($existing) {
            return response()->json($this->format($existing), 200);
        }

        $data = [
            'name'                => $spec['name'],
            'external_id'         => $spec['external_id'],
            'owner_id'            => $spec['owner_id'],
            'egg_id'              => $spec['egg_id'],
            'environment'         => $spec['environment'],
            'cpu'                 => $spec['cpu'],
            'memory'              => $spec['memory'],
            'disk'                => $spec['disk'],
            'swap'                => $spec['swap'],
            'io'                  => $spec['io'],
            'allocation_limit'    => $spec['allocation_limit'],
            'database_limit'      => $spec['database_limit'],
            'backup_limit'        => $spec['backup_limit'],
            'start_on_completion' => $spec['start_on_completion'] ?? true,
            'skip_scripts'        => $spec['skip_scripts'] ?? false,
            'oom_killer'          => $spec['oom_killer'] ?? false,
        ];

        if (!empty($placement['node_ids'])) {
            $server = $this->creation->handle(
                array_merge($data, $this->planner->plan($placement['node_ids'], $placement['ports'])),
            );
        } else {
            $server = $this->creation->handle(
                $data,
                $this->planner->deploymentObject($placement['tags'], $placement['ports']),
            );
        }

        return response()->json($this->format($server), 201);
    }

    private function format(Server $server): array
    {
        return [
            'id'          => $server->id,
            'uuid'        => $server->uuid,
            'identifier'  => $server->uuid_short,
            'name'        => $server->name,
            'external_id' => $server->external_id,
            'node_id'     => $server->node_id,
            'suspended'   => $server->isSuspended(),
        ];
    }
}
