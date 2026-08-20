<?php

namespace Hexalabs\BillingBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\Node;
use Hexalabs\BillingBridge\Http\Requests\ServerLifecycleRequest;
use Illuminate\Http\JsonResponse;
use Throwable;

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
 * The storefront's `/status` reads local tables and nothing else — a synchronous
 * probe of the panel would make a panel outage into a status page that hangs,
 * i.e. the page fails in exactly the circumstance it exists for. So **every
 * network call the status page depends on happens here**, on the panel's own
 * side of the wire, fifteen minutes before anybody reads the result.
 *
 * That is why `daemon_reachable` and `cpu_percent` exist. Pelican stores no
 * last-contact timestamp and no load history anywhere; `Node::statistics()` asks
 * the daemon live. Without those two fields a dead machine mirrors identically
 * to a healthy one, and the storefront can say nothing about how hard a
 * datacentre is working.
 *
 * **Both come out of one call.** `statistics()` returns reachability and load
 * together, so publishing the load figure costs the panel nothing it was not
 * already doing for the heartbeat.
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
            ->orderBy('name')
            ->get()
            ->map(function (Node $node) use ($deadline) {
                $probe = microtime(true) < $deadline ? $this->probe($node) : null;

                return [
                    'id'               => $node->id,
                    'uuid'             => $node->uuid,
                    'name'             => $node->name,
                    'fqdn'             => $node->fqdn,
                    'public'           => $node->public,
                    'maintenance_mode' => $node->maintenance_mode,
                    'tags'             => $node->tags ?? [],
                    // true = answered, false = did not, null = not checked. All
                    // three are distinct to the storefront and the third is not
                    // a synonym for the second.
                    'daemon_reachable' => $probe['reachable'] ?? null,
                    // 0-100 across the whole machine, already normalised — the
                    // panel's own node chart multiplies this by the thread count
                    // to get the htop-style per-core sum, so unmultiplied it is
                    // exactly "how busy is this box". Null whenever there is no
                    // reading, which is never the same as zero.
                    'cpu_percent'      => $probe['cpu_percent'] ?? null,
                    'allocations'      => $node->allocations->map(fn (Allocation $allocation) => [
                        'id'       => $allocation->id,
                        'ip'       => $allocation->ip,
                        'ip_alias' => $allocation->ip_alias,
                        'port'     => $allocation->port,
                        'assigned' => $allocation->server_id !== null,
                    ])->values(),
                ];
            });

        return response()->json(['data' => $nodes]);
    }

    /**
     * Ask one daemon how it is, or null if the question could not be put.
     *
     * `Node::statistics()` swallows every transport failure and returns a
     * zero-filled default, so in the normal case there is no exception to catch
     * — the only signal that the call failed is the payload being empty.
     * `memory_total` is the field the panel itself tests for that, and a running
     * machine cannot report zero total memory, so the test is sound rather than
     * a heuristic. Reproducing a different one here would be a second
     * implementation of "did the daemon answer".
     *
     * The `catch` is not for that. It is for the method not being there, or not
     * behaving as documented, on a panel build this was never compiled against —
     * the failure mode the bridge's whole "verify after any panel upgrade" rule
     * exists for. An exception here would 500 `GET /nodes` and take the entire
     * mirror stale, folding every location on the status page to Unknown; a null
     * costs one node's reading and nothing else. Same trade as the budget.
     *
     * The result is cached panel-side for a few seconds, which does not help
     * across a fifteen-minute sync interval — every call from the storefront
     * pays for a real probe. That is the intent: a cached heartbeat is not a
     * heartbeat.
     *
     * @return array{reachable: bool, cpu_percent: ?float}|null
     */
    private function probe(Node $node): ?array
    {
        try {
            $statistics = $node->statistics();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (empty($statistics['memory_total'])) {
            // Reached the code path, got nothing back. Explicitly no load
            // reading rather than 0.0 — a machine we cannot talk to is not a
            // machine that is idle, and the storefront averages these.
            return ['reachable' => false, 'cpu_percent' => null];
        }

        return [
            'reachable'   => true,
            'cpu_percent' => round((float) ($statistics['cpu_percent'] ?? 0), 1),
        ];
    }
}
