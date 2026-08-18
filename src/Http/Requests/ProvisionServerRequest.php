<?php

namespace Hexalabs\BillingBridge\Http\Requests;

use Closure;
use Cron\CronExpression;

class ProvisionServerRequest extends BillingApiRequest
{
    public function rules(): array
    {
        return [
            'server'                     => ['required', 'array'],
            'server.name'                => ['required', 'string', 'max:255'],
            'server.external_id'         => ['required', 'string', 'max:255'],
            'server.owner_id'            => ['required', 'integer', 'exists:users,id'],
            'server.egg_id'              => ['required', 'integer', 'exists:eggs,id'],
            'server.environment'         => ['present', 'array'],
            'server.cpu'                 => ['required', 'integer', 'min:0'],
            'server.memory'              => ['required', 'integer', 'min:0'],
            'server.disk'                => ['required', 'integer', 'min:0'],
            'server.swap'                => ['required', 'integer'],
            'server.io'                  => ['required', 'integer', 'min:10', 'max:1000'],
            'server.allocation_limit'    => ['required', 'integer', 'min:0'],
            'server.database_limit'      => ['required', 'integer', 'min:0'],
            'server.backup_limit'        => ['required', 'integer', 'min:0'],
            'server.start_on_completion' => ['sometimes', 'boolean'],
            'server.skip_scripts'        => ['sometimes', 'boolean'],
            'server.oom_killer'          => ['sometimes', 'boolean'],

            // Optional: the storefront asking for scheduled backups to exist
            // from the first minute of the server's life. Absent means "do not
            // create one", which is how an operator turns the feature off
            // without a plugin release.
            'server.backup_schedule'              => ['sometimes', 'array', $this->validCronExpression()],
            'server.backup_schedule.name'         => ['required_with:server.backup_schedule', 'string', 'max:255'],
            'server.backup_schedule.minute'       => ['required_with:server.backup_schedule', 'string', 'max:255'],
            'server.backup_schedule.hour'         => ['required_with:server.backup_schedule', 'string', 'max:255'],
            'server.backup_schedule.day_of_month' => ['required_with:server.backup_schedule', 'string', 'max:255'],
            'server.backup_schedule.month'        => ['required_with:server.backup_schedule', 'string', 'max:255'],
            'server.backup_schedule.day_of_week'  => ['required_with:server.backup_schedule', 'string', 'max:255'],

            'placement'            => ['required', 'array'],
            'placement.node_ids'   => ['present', 'array'],
            'placement.node_ids.*' => ['integer'],
            'placement.ports'      => ['present', 'array'],
            'placement.tags'       => ['present', 'array'],
            'placement.tags.*'     => ['string'],
        ];
    }

    /**
     * The five fields have to parse as a crontab line *here*, because the panel
     * evaluates them to a `next_run_at` the moment the schedule is stored and
     * throws if it cannot. Rejecting the request is a 422 the storefront logs
     * against the order; letting it through is an exception thrown halfway
     * through provisioning, after the server already exists.
     */
    private function validCronExpression(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (!is_array($value)) {
                return;
            }

            $fields = ['minute', 'hour', 'day_of_month', 'month', 'day_of_week'];

            foreach ($fields as $field) {
                if (!is_string($value[$field] ?? null)) {
                    // The per-field rules already report this; bailing keeps
                    // the message about the missing field rather than about an
                    // expression nobody wrote.
                    return;
                }
            }

            $expression = implode(' ', array_map(fn (string $field) => $value[$field], $fields));

            if (!CronExpression::isValidExpression($expression)) {
                $fail("The backup schedule does not evaluate to a valid cron expression ({$expression}).");
            }
        };
    }
}
