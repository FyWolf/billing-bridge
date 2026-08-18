<?php

namespace Hexalabs\BillingBridge\Http\Controllers;

use App\Helpers\Utilities;
use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\Task;
use App\Services\Servers\ServerCreationService;
use Hexalabs\BillingBridge\Http\Requests\ProvisionServerRequest;
use Hexalabs\BillingBridge\Services\DeploymentPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
            return response()->json($this->format($existing, $this->ensureBackupSchedule($existing, $spec)), 200);
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

        return response()->json($this->format($server, $this->ensureBackupSchedule($server, $spec)), 201);
    }

    /** @return array<string, mixed> */
    private function format(Server $server, ?int $backupScheduleId): array
    {
        return [
            'id'                 => $server->id,
            'uuid'               => $server->uuid,
            'identifier'         => $server->uuid_short,
            'name'               => $server->name,
            'external_id'        => $server->external_id,
            'node_id'            => $server->node_id,
            'suspended'          => $server->isSuspended(),
            'backup_schedule_id' => $backupScheduleId,
        ];
    }

    /**
     * Give the server a daily backup schedule, if the storefront asked for one.
     *
     * Schedules live **only on the panel's client API**, which is reached with a
     * user session over a server that user owns. The billing service holds an
     * application key and no session, so there is no way for it to set this up
     * itself — the same gap that justifies the rest of this plugin.
     *
     * Deliberately part of the provisioning request rather than a second call.
     * A follow-up call that failed would leave the storefront holding a server
     * with no backups and no way back: `PanelProvisioner::provision()` returns
     * early once `panel_server_id` is set, so the retry that would fix it never
     * runs the code that failed. Folding it in here means the *whole* thing is
     * retried, and the retry lands on the `$existing` branch above — which is
     * why that branch calls this too.
     *
     * Returns the schedule id when there is one, so the storefront can record
     * in its audit log whether backups were actually set up.
     *
     * @param  array<string, mixed>  $spec
     */
    private function ensureBackupSchedule(Server $server, array $spec): ?int
    {
        $schedule = $spec['backup_schedule'] ?? null;

        if (!$schedule) {
            return null;
        }

        // `CreateBackupSchema::canCreate()` says the same thing, and
        // `InitiateBackupService` throws TooManyBackupsException on a server
        // with no allowance. Scheduling one anyway buys a failed task every
        // night for a plan that never included backups.
        if ($server->backup_limit < 1) {
            return null;
        }

        // Idempotency, and the reason it keys on the *task* rather than on the
        // schedule's name: what must not be duplicated is scheduled backups,
        // and the customer is free to rename the schedule the moment they see
        // it. Anything already backing this server up on a timer wins.
        $existing = Schedule::query()
            ->where('server_id', $server->id)
            ->whereRelation('tasks', 'action', '=', 'backup')
            ->first();

        if ($existing) {
            return $existing->id;
        }

        return DB::transaction(function () use ($server, $schedule) {
            /** @var Schedule $model */
            $model = Schedule::query()->create([
                'server_id'         => $server->id,
                'name'              => $schedule['name'],
                'cron_minute'       => $schedule['minute'],
                'cron_hour'         => $schedule['hour'],
                'cron_day_of_month' => $schedule['day_of_month'],
                'cron_month'        => $schedule['month'],
                'cron_day_of_week'  => $schedule['day_of_week'],
                'is_active'         => true,
                // A stopped server still has a world worth keeping, and a
                // customer who stopped theirs for a month is exactly who
                // notices a missing backup.
                'only_when_online'  => false,
                // Without this the schedule is stored but never picked up:
                // `p:schedule:process` selects on `next_run_at <= now()`, and
                // NULL never satisfies it.
                'next_run_at'       => Utilities::getScheduleNextRunDate(
                    $schedule['minute'],
                    $schedule['hour'],
                    $schedule['day_of_month'],
                    $schedule['month'],
                    $schedule['day_of_week'],
                ),
            ]);

            Task::query()->create([
                'schedule_id'         => $model->id,
                'sequence_id'         => 1,
                'action'              => 'backup',
                // Files to ignore. Empty means the whole server, which is what
                // "automatic backups" has to mean when nobody has been asked.
                'payload'             => '',
                'time_offset'         => 0,
                'continue_on_failure' => false,
            ]);

            return $model->id;
        });
    }
}
