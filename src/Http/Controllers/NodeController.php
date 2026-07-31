<?php

namespace Hexalabs\BillingBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\Node;
use Hexalabs\BillingBridge\Http\Requests\ServerLifecycleRequest;
use Illuminate\Http\JsonResponse;

/**
 * Feeds the billing service's local node cache.
 *
 * The pack form lets an admin pin deployment nodes and pick ports out of a
 * list. Neither exists on the core application API for a `billing`-scoped key,
 * so — exactly like eggs — nodes and their allocations are mirrored into
 * `panel_nodes` on a schedule rather than fetched on every form render.
 *
 * Read-only: it never mutates panel state, so it keeps the bridge free of
 * migrations, packages and Filament, per the bridge's design contract.
 */
class NodeController extends Controller
{
    public function __invoke(ServerLifecycleRequest $request): JsonResponse
    {
        $nodes = Node::query()
            ->with(['allocations' => fn ($query) => $query->orderBy('ip')->orderBy('port')])
            ->orderBy('name')
            ->get()
            ->map(fn (Node $node) => [
                'id'               => $node->id,
                'uuid'             => $node->uuid,
                'name'             => $node->name,
                'fqdn'             => $node->fqdn,
                'public'           => $node->public,
                'maintenance_mode' => $node->maintenance_mode,
                'tags'             => $node->tags ?? [],
                'allocations'      => $node->allocations->map(fn (Allocation $allocation) => [
                    'id'       => $allocation->id,
                    'ip'       => $allocation->ip,
                    'ip_alias' => $allocation->ip_alias,
                    'port'     => $allocation->port,
                    'assigned' => $allocation->server_id !== null,
                ])->values(),
            ]);

        return response()->json(['data' => $nodes]);
    }
}
