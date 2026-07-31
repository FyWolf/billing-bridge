<?php

namespace Hexalabs\BillingBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\UserCreationService;
use Hexalabs\BillingBridge\Http\Requests\ServerLifecycleRequest;
use Hexalabs\BillingBridge\Http\Requests\StoreUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function __construct(private readonly UserCreationService $creation)
    {
    }

    /**
     * Create the panel account for a billing customer.
     *
     * Provisioning needs an `owner_id` before the customer has ever visited the
     * panel, so the account is made eagerly at registration. `external_id`
     * carries the billing customer id, and `is_managed_externally` locks panel
     * self-service so the two sides cannot drift apart.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $externalId = $request->validated('external_id');

        $existing = User::where('external_id', $externalId)
            ->orWhere('email', $request->validated('email'))
            ->first();

        if ($existing) {
            // Adopt a pre-existing account rather than failing on the unique
            // email constraint.
            if (blank($existing->external_id)) {
                $existing->update(['external_id' => $externalId, 'is_managed_externally' => true]);
            }

            return response()->json($this->format($existing), 200);
        }

        $user = $this->creation->handle([
            'external_id'           => $externalId,
            'email'                 => $request->validated('email'),
            'username'              => $this->uniqueUsername($request->validated('username')),
            'password'              => Str::password(32),
            'is_managed_externally' => true,
        ]);

        return response()->json($this->format($user), 201);
    }

    public function showByExternalId(ServerLifecycleRequest $request, string $externalId): JsonResponse
    {
        $user = User::where('external_id', $externalId)->first();

        if (!$user) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json($this->format($user));
    }

    private function uniqueUsername(string $username): string
    {
        $candidate = Str::of($username)->lower()->replaceMatches('/[^a-z0-9_.-]/', '')->limit(24, '')->value();
        $candidate = $candidate ?: 'customer';
        $base      = $candidate;
        $suffix    = 1;

        while (User::where('username', $candidate)->exists()) {
            $candidate = $base . $suffix++;
        }

        return $candidate;
    }

    private function format(User $user): array
    {
        return [
            'id'          => $user->id,
            'uuid'        => $user->uuid,
            'email'       => $user->email,
            'username'    => $user->username,
            'external_id' => $user->external_id,
        ];
    }
}
