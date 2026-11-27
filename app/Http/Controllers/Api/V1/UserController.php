<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $users = User::with('roles')->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $users->getCollection()->map(fn (User $user) => $this->userData($user))->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $roles = $validated['roles'] ?? [];

        if (in_array('superadmin', $roles, true) && ! $request->user()->can('roles.update')) {
            return $this->forbidden();
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $user->syncRoles($roles);
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'User berhasil dibuat.',
            'data' => $this->userData($user),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles');

        return response()->json([
            'success' => true,
            'data' => $this->userData($user),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        if ($user->hasRole('superadmin') && ! $request->user()->can('roles.update')) {
            return $this->forbidden();
        }

        if (in_array('superadmin', $validated['roles'] ?? [], true) && ! $request->user()->can('roles.update')) {
            return $this->forbidden();
        }

        if (
            $user->hasRole('superadmin')
            && array_key_exists('roles', $validated)
            && ! in_array('superadmin', $validated['roles'], true)
            && User::role('superadmin')->count() <= 1
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Minimal harus ada satu akun superadmin.',
            ], 422);
        }

        $user->fill(array_intersect_key($validated, array_flip(['name', 'email'])));

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        if (array_key_exists('roles', $validated)) {
            $user->syncRoles($validated['roles']);
        }

        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'User berhasil diperbarui.',
            'data' => $this->userData($user),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->hasRole('superadmin') && ! $request->user()->can('roles.update')) {
            return $this->forbidden();
        }

        if ($user->hasRole('superadmin') && User::role('superadmin')->count() <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'Minimal harus ada satu akun superadmin.',
            ], 422);
        }

        if ($request->user()->is($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa menghapus akun sendiri.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User berhasil dihapus.',
        ]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Hanya superadmin yang boleh mengelola akun atau role superadmin.',
        ], 403);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
