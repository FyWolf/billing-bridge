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
 * **All three come out of one call.** `statistics()` returns reachability and
 * load together and the clock around it costs nothing, so publishing the load
 * figure and the probe latency costs the panel nothing it was not already doing
 * for the heartbeat.
 *
 * `daemon_latency_ms` is the newest of the three and the narrowest: it is
 * admin-only on the storefront, because it measures the panel's own network to
 * its own daemon. The storefront's public latency figure is measured in the
 * *visitor's* browser for exactly this reason — a server-side number presented
 * under a heading a customer reads as "how far is this from me" would be a
 * systematically flattering claim about somebody else's connection.
 *
 * ## `server_ids` exists so a maintenance notice can name one machine
 *
 * The storefront has no way to know which of its customers sit on which node.
 * `orders` records no node — a pack's pinned node list is only the *placement
 * request*, and `DeploymentPlanner` picks the actual node without reporting
 * back. So the finest targeting the storefront could manage was a whole
 * datacentre, which is the wrong granularity for work on one box.
 *
 * The alternative was for the storefront to record the chosen node on the order
 * at provision time. That was rejected: it goes stale the moment an operator
 * migrates a server between nodes panel-side, and nothing tells the storefront.
 * A stale node means a maintenance notice reaching the wrong customers, which is
 * worse than sending none — so the question is answered from live panel state on
 * every sync, and a migration is reflected within one interval.
 *
 * **Bare integers and nothing else.** No names, no owners, no external ids. A
 * billing-scoped key does see ids of servers it did not create, which is the one
 * real cost here: the storefront intersects them with the `panel_server_id`
 * values it already holds, so an id it did not issue tells it nothing it can act
 * on, and a count of them is something `assigned` already implied.
 *
 * **An empty array and an absent key are different facts.** Empty means the node
 * has no servers; absent means a bridge older than this change, which cannot
 * answer the question at all. The storefront stores the second as null and
 * *refuses to send* a node-scoped mailing against it, rather than treating an
 * unknown as an empty node and quietly mailing nobody. Same tri-state discipline
 * as `daemon_reachable` above, and for the same reason: the collapsed case fails
 * silently, in the direction of doing nothing, at the moment somebody needed it
 * to work.
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
            ->with([
                'allocations' => fn ($query) => $query->orderBy('ip')->orderBy('port'),
                // Ids and the foreign key, nothing else. See `serverIds()`.
                'servers' => fn ($query) => $query->select(['id', 'node_id'])->orderBy('id'),
            ])
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
                    // How long this probe took, in whole milliseconds. Admin-only
                    // debug detail on the storefront: it is the panel talking to its
                    // own daemon, which answers "is that machine slow to answer us"
                    // and nothing about any customer's ping — the storefront's public
                    // latency is measured in the visitor's own browser, deliberately,
                    // and these two must never be shown as the same number.
                    //
                    // Null on a probe that failed as well as on one that never ran. A
                    // failure's duration is the timeout, not the node's latency, and
                    // storing it would make a node look slower the more broken it got.
                    'daemon_latency_ms' => $probe['latency_ms'] ?? null,
                    // Which servers are on this node, right now. Always an array —
                    // an empty one means "no servers", and the *absence* of this key
                    // means an older bridge. Those are different facts and the
                    // storefront must not collapse them; see the method docblock.
                    'server_ids'       => $node->servers->pluck('id')->map(fn ($id) => (int) $id)->values(),
                    'allocations'      => $node->allocations->map(fn (Allocation $allocation) => [
                        'id'       => $allocation->id,
                        'ip'       => $allocation->ip,
                        'ip_alias' => $allocation->ip_alias,
                        'port'     => $allocation->port,
                        // Kept alongside `server_ids` rather than replaced by it. A
                        // storefront older than this change reads only this, and the
                        // two are answers to different questions anyway: how many
                        // ports are free, versus who is on the box.
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
     * The elapsed time is taken around `statistics()` alone, so it is the round
     * trip to the daemon and not this endpoint's own bookkeeping. It is only
     * reported for a call that answered: the duration of a *failed* probe is the
     * timeout above, which is a constant, and publishing it as latency would make
     * a node appear to slow down in exact proportion to how broken it is.
     *
     * @return array{reachable: bool, cpu_percent: ?float, latency_ms: ?int}|null
     */
    private function probe(Node $node): ?array
    {
        $started = microtime(true);

        try {
            $statistics = $node->statistics();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        if (empty($statistics['memory_total'])) {
            // Reached the code path, got nothing back. Explicitly no load
            // reading rather than 0.0 — a machine we cannot talk to is not a
            // machine that is idle, and the storefront averages these.
            return ['reachable' => false, 'cpu_percent' => null, 'latency_ms' => null];
        }

        return [
            'reachable'   => true,
            'cpu_percent' => round((float) ($statistics['cpu_percent'] ?? 0), 1),
            'latency_ms'  => $elapsedMs,
        ];
    }
}
