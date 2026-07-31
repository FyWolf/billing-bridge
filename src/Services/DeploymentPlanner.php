<?php

namespace Hexalabs\BillingBridge\Services;

use App\Models\Allocation;
use App\Models\Objects\DeploymentObject;
use App\Models\Server;
use RuntimeException;

/**
 * Node and allocation selection.
 *
 * This is the main reason the bridge exists: the panel's application API has no
 * equivalent for picking the least-CPU-loaded node out of a pinned set. Doing it
 * externally would mean paginating every node's allocations and every server's
 * cpu over HTTP on each order. The logic below is carried over unchanged from
 * the plugin's WingsProvisioner.
 */
class DeploymentPlanner
{
    /**
     * Resolve explicit node_id + allocation_id for a pinned-node pack.
     *
     * @param  int[]  $nodeIds
     * @param  array<int|string>  $portRanges
     * @return array{node_id: int, allocation_id: int}
     */
    public function plan(array $nodeIds, array $portRanges): array
    {
        $ports = $this->expandPorts($portRanges);

        $candidateNodeIds = Allocation::query()
            ->whereIn('node_id', $nodeIds)
            ->whereNull('server_id')
            ->when(!empty($ports), fn ($q) => $q->whereIn('port', $ports))
            ->distinct()
            ->pluck('node_id');

        if ($candidateNodeIds->isEmpty()) {
            throw new RuntimeException(
                'No available allocations on the configured nodes'
                . (!empty($ports) ? ' matching ports: ' . implode(', ', $portRanges) : '')
                . '.'
            );
        }

        $usedCores = Server::whereIn('node_id', $candidateNodeIds)
            ->selectRaw('node_id, SUM(cpu) / 100.0 as used_cores')
            ->groupBy('node_id')
            ->pluck('used_cores', 'node_id');

        $bestNodeId = $candidateNodeIds
            ->sortBy(fn ($nodeId) => $usedCores->get($nodeId, 0))
            ->first();

        $allocation = Allocation::query()
            ->where('node_id', $bestNodeId)
            ->whereNull('server_id')
            ->when(!empty($ports), fn ($q) => $q->whereIn('port', $ports))
            ->inRandomOrder()
            ->first();

        if (!$allocation) {
            throw new RuntimeException(
                'No available allocations on the selected node'
                . (!empty($ports) ? ' matching ports: ' . implode(', ', $portRanges) : '')
                . '.'
            );
        }

        return [
            'node_id'       => $allocation->node_id,
            'allocation_id' => $allocation->id,
        ];
    }

    /**
     * Fall back to the panel's own deployment pipeline (FindViableNodesService
     * + AllocationSelectionService) when the pack pins no nodes.
     *
     * @param  string[]  $tags
     * @param  array<int|string>  $portRanges
     */
    public function deploymentObject(array $tags, array $portRanges): DeploymentObject
    {
        $object = new DeploymentObject();
        $object->setDedicated(false);
        $object->setTags($tags);
        $object->setPorts($portRanges);

        return $object;
    }

    /**
     * "25565-25570" and "25565" both become a flat list of ints.
     *
     * @param  array<int|string>  $portRanges
     * @return int[]
     */
    private function expandPorts(array $portRanges): array
    {
        $ports = [];

        foreach ($portRanges as $portRange) {
            if (str_contains((string) $portRange, '-')) {
                [$start, $end] = explode('-', (string) $portRange, 2);
                $ports = array_merge($ports, range((int) $start, (int) $end));
            } else {
                $ports[] = (int) $portRange;
            }
        }

        return $ports;
    }
}
