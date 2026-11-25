<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{

    private function ownerDenied(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => 'OWNER_PROTECTED', 'message' => $message],
        ], 403);
    }

    /** Only an Owner may create, promote, demote or remove an Owner; the last Owner can never be removed. */
    private function isOwner(User $u): bool
    {
        return $u->hasRole('Owner');
    }

    private function ownerCount(): int
    {
        return User::role('Owner')->count();
    }

    /**
     * List users with roles.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles')
            ->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Directly create a new user with name, email, password, and role.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|string|exists:roles,name',
        ]);

        if ($request->role === 'Owner' && ! $this->isOwner($request->user())) {
            return $this->ownerDenied('Only an Owner can create another Owner.');
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => \Illuminate\Support\Facades\Hash::make($request->password),
            'email_verified_at' => now(),
        ]);

        $user->assignRole($request->role);

        AuditLog::log('user.created', $request->user()->id, 'User', (string)$user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $request->role,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => [$request->role],
                'created_at' => $user->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Update user's role.
     */
    public function updateRole(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        $targetUser = User::findOrFail($id);

        // Prevent self demoting or removing owner
        if ($targetUser->id === $request->user()->id && $request->role !== 'Owner') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ACTION_NOT_ALLOWED',
                    'message' => 'You cannot modify your own role.',
                ],
            ], 403);
        }

        $actorIsOwner = $this->isOwner($request->user());
        if (($request->role === 'Owner' || $this->isOwner($targetUser)) && ! $actorIsOwner) {
            return $this->ownerDenied('Only an Owner can assign or change the Owner role.');
        }
        if ($this->isOwner($targetUser) && $request->role !== 'Owner' && $this->ownerCount() <= 1) {
            return $this->ownerDenied('The last Owner cannot be demoted.');
        }

        $oldRole = $targetUser->getRoleNames()->first();
        $targetUser->syncRoles([$request->role]);

        AuditLog::log('user.role_updated', $request->user()->id, 'User', (string)$targetUser->id, [
            'old_role' => $oldRole,
            'new_role' => $request->role,
        ]);

        return response()->json([
            'success' => true,
            'message' => "User role updated to {$request->role}.",
            'data' => [
                'id' => $targetUser->id,
                'email' => $targetUser->email,
                'role' => $request->role,
            ],
        ]);
    }

    /**
     * Remove / deactivate a user.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $targetUser = User::findOrFail($id);

        if ($targetUser->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ACTION_NOT_ALLOWED',
                    'message' => 'You cannot delete your own account.',
                ],
            ], 403);
        }

        if ($this->isOwner($targetUser)) {
            if (! $this->isOwner($request->user())) {
                return $this->ownerDenied('Only an Owner can remove an Owner.');
            }
            if ($this->ownerCount() <= 1) {
                return $this->ownerDenied('The last Owner cannot be removed.');
            }
        }

        AuditLog::log('user.deleted', $request->user()->id, 'User', (string)$targetUser->id, [
            'email' => $targetUser->email,
        ]);

        $targetUser->delete();

        return response()->json([
            'success' => true,
            'message' => 'User account removed.',
        ]);
    }
}
