<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
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
     * Invite a new application user.
     */
    public function invite(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'role' => 'required|string|exists:roles,name',
        ]);

        $token = Str::random(40);

        $invitation = Invitation::create([
            'email' => $request->email,
            'role' => $request->role,
            'token' => $token,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        AuditLog::log('user.invited', $request->user()->id, 'Invitation', (string)$invitation->id, [
            'email' => $request->email,
            'role' => $request->role,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Invitation generated successfully.',
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'token' => $invitation->token,
                'expires_at' => $invitation->expires_at->toIso8601String(),
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
