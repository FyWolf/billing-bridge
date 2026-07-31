<?php

namespace Hexalabs\BillingBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\UserCreationService;
use Hexalabs\BillingBridge\Http\Requests\ServerLifecycleRequest;
use Hexalabs\BillingBridge\Http\Requests\StoreUserRequest;
use Hexalabs\BillingBridge\Http\Requests\UpdateUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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

    /**
     * Push a profile change from the billing app.
     *
     * Panel users created here carry `is_managed_externally`, which locks
     * panel-side self-service — so the billing app is the source of truth and
     * has to be able to write changes through.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->assertBillingManaged($user);

        $data = array_filter([
            'email'    => $request->validated('email'),
            'username' => $request->validated('username'),
        ], fn ($value) => $value !== null);

        if ($data !== []) {
            if (isset($data['username']) && $data['username'] !== $user->username) {
                $data['username'] = $this->uniqueUsername($data['username']);
            }

            $user->update($data);
        }

        return response()->json($this->format($user->fresh()));
    }

    public function destroy(ServerLifecycleRequest $request, User $user): JsonResponse
    {
        $this->assertBillingManaged($user);

        $user->delete();

        return response()->json([], 204);
    }

    /**
     * Refuse anything the billing service did not create. Without this a
     * leaked billing key could rewrite or delete a hand-made panel admin.
     */
    private function assertBillingManaged(User $user): void
    {
        if (blank($user->external_id) || !$user->is_managed_externally) {
            throw new AccessDeniedHttpException(
                "User #{$user->id} is not managed by billing and cannot be modified through this endpoint."
            );
        }
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
