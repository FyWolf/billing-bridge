<?php

namespace Hexalabs\BillingBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Egg;
use Hexalabs\BillingBridge\Http\Requests\ServerLifecycleRequest;
use Illuminate\Http\JsonResponse;

/**
 * Feeds the billing service's local egg cache.
 *
 * The plugin had a foreign key to `eggs` and could read variables inline. Out
 * of process the billing admin still needs them — the pack-price form builds an
 * egg-variable repeater — so they are mirrored into `panel_eggs` on a schedule.
 */
class EggController extends Controller
{
    public function __invoke(ServerLifecycleRequest $request): JsonResponse
    {
        $eggs = Egg::with('variables')->orderBy('name')->get()->map(fn (Egg $egg) => [
            'id'            => $egg->id,
            'uuid'          => $egg->uuid,
            'name'          => $egg->name,
            'description'   => $egg->description,
            'docker_images' => $egg->docker_images,
            'variables'     => $egg->variables->map(fn ($variable) => [
                'id'            => $variable->id,
                'name'          => $variable->name,
                'description'   => $variable->description,
                'env_variable'  => $variable->env_variable,
                'default_value' => $variable->default_value,
                'user_viewable' => $variable->user_viewable,
                'user_editable' => $variable->user_editable,
                'rules'         => $variable->rules,
            ])->values(),
        ]);

        return response()->json(['data' => $eggs]);
    }
}
