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
 *
 * ## This endpoint also feeds the public status page
 *
 * The storefront's `/status` reads only local tables — a synchronous probe of
 * the panel would make a panel outage into a status page that hangs, i.e. the
 * page fails exactly when it is needed. So **every network call the status page
 * depends on happens here**, on the panel's own side of the wire, fifteen
 * minutes before anybody reads the result.
 *
 * That is why `daemon_reachable` exists. Pelican stores no last-contact
 * timestamp anywhere; `Node::statistics()` asks the daemon live. Without this
 * field a dead machine mirrors identically to a healthy one and the storefront
 * can never say Down without an operator typing an incident by hand.
 */
class NodeController extends Controller
{
    /**
     * Wall-clock seconds this endpoint may spend probing daemons, in total.
     *
     * `Node::statistics()` costs ~2s against a node that is down (a one-second
     * connect timeout plus a one-second read timeout) and almost nothing
     * against one that is up. So the cost of this endpoint scales with the
     * number of *broken* nodes — which is precisely the moment the storefront
     * most needs an answer, and precisely when an unbounded loop would blow
     * through its HTTP timeout and fail the sync outright.
     *
     * A failed sync is strictly worse than a missing heartbeat: it takes the
     * whole mirror stale, so every location on the status page folds to
     * Unknown, including the ones that are fine. Past the budget the remaining
     * nodes report `daemon_reachable: null` — "not checked" — which the
     * storefront treats as no heartbeat data rather than as a dead node.
     *
     * Nodes are probed in the order they are listed, so which ones fall off the
     * end is deterministic and diagnosable rather than a different set each run.
     */
    private const PROBE_BUDGET_SECONDS = 10.0;

    public function __invoke(ServerLifecycleRequest $request): JsonResponse
    {
        $deadline = microtime(true) + self::PROBE_BUDGET_SECONDS;

        $nodes = Node::query()
            ->with(['allocations' => fn ($query) => $query->orderBy('ip')->orderBy('port')])
            // What the panel has *committed* to servers on this node, which is
            // the question "is there room here" — not what the machines are
            // using right now. It is a database aggregate, so it costs no
            // daemon call and cannot flap.
            ->withSum('servers', 'memory')
            ->withSum('servers', 'disk')
            ->withSum('servers', 'cpu')
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
                // true = answered, false = did not, null = not checked. All
                // three are distinct to the storefront and the third is not a
                // synonym for the second.
                'daemon_reachable' => microtime(true) < $deadline
                    ? $this->daemonAnswers($node)
                    : null,
                'resources'        => $this->resources($node),
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

    /**
     * Whether the node's daemon answered.
     *
     * `Node::statistics()` swallows every transport failure and returns a
     * zero-filled default, so there is no exception to catch — the only signal
     * that the call failed is the payload being empty. `memory_total` is the
     * field the panel itself tests for that (`Node::statistics()`), and a
     * running machine cannot report zero total memory, so the test is sound
     * rather than a heuristic. Reproducing a different one here would be a
     * second implementation of "did the daemon answer".
     *
     * The result is cached panel-side for a few seconds, which does not help
     * across a fifteen-minute sync interval — every call from the storefront
     * pays for a real probe. That is the intent: a cached heartbeat is not a
     * heartbeat.
     */
    private function daemonAnswers(Node $node): bool
    {
        return !empty($node->statistics()['memory_total']);
    }

    /**
     * Totals and committed sums, for the status page's capacity figure.
     *
     * A zero total means *unlimited* in Pelican, not "no capacity" —
     * `Node::isViable()` skips any dimension whose total is zero. The storefront
     * has to make the same exception, so the raw zero is passed through rather
     * than being turned into a null here; one of the two ends has to own that
     * rule and it is the end that publishes the number.
     *
     * The overallocate percentages come too, because "how full is this" and
     * "can another server still fit" are different questions with different
     * denominators, and only the storefront knows which one it is answering.
     *
     * @return array<string, int>
     */
    private function resources(Node $node): array
    {
        return [
            'memory'              => (int) $node->memory,
            'memory_allocated'    => $this->committed($node, 'memory'),
            'memory_overallocate' => (int) $node->memory_overallocate,
            'disk'                => (int) $node->disk,
            'disk_allocated'      => $this->committed($node, 'disk'),
            'disk_overallocate'   => (int) $node->disk_overallocate,
            'cpu'                 => (int) $node->cpu,
            'cpu_allocated'       => $this->committed($node, 'cpu'),
            'cpu_overallocate'    => (int) $node->cpu_overallocate,
        ];
    }

    /**
     * A `withSum` aggregate, read the one way that actually works here.
     *
     * `Node` declares `public int $servers_sum_memory = 0` (and the disk and cpu
     * equivalents) as real typed properties. A declared property is resolved
     * before `__get()`, so `$node->servers_sum_memory` returns that **zero**
     * and never reaches the aggregate `withSum` put in the attribute bag.
     * `getAttribute()` reads the bag directly.
     *
     * This is worth the indirection precisely because the wrong version returns
     * a plausible number: every node would report nothing committed, the status
     * page would show a fleet at 0% with limitless headroom, and there would be
     * nothing anywhere to suggest it was untrue.
     */
    private function committed(Node $node, string $resource): int
    {
        return (int) $node->getAttribute("servers_sum_{$resource}");
    }
}
