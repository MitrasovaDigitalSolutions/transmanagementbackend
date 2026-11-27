<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn (Role $role) => $this->roleData($role))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // Roles live on the 'web' guard the seeder uses; don't inherit a Sanctum test guard.
        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);
        $role->syncPermissions($validated['permissions'] ?? []);
        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'Role berhasil dibuat.',
            'data' => $this->roleData($role),
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');

        return response()->json([
            'success' => true,
            'data' => $this->roleData($role),
        ]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Role superadmin tidak bisa diubah.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if (array_key_exists('name', $validated)) {
            $role->name = $validated['name'];
            $role->save();
        }

        if (array_key_exists('permissions', $validated)) {
            $role->syncPermissions($validated['permissions']);
        }

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'Role berhasil diperbarui.',
            'data' => $this->roleData($role),
        ]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'superadmin' || $role->users()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Role tidak bisa dihapus karena masih dipakai.',
            ], 422);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role berhasil dihapus.',
        ]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Permission::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function roleData(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
        ];
    }
}
