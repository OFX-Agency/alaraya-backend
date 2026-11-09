<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
    }

    /**
     * Get all roles with the permissions each role grants.
     */
    public function roles(Request $request)
    {
        $this->ensureAdmin($request);

        $roles = Role::with('permissions:id,name')->select('id', 'name')->orderBy('id')->get();

        return response()->json([
            'status' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Get all permissions
     */
    public function permissions(Request $request)
    {
        $this->ensureAdmin($request);

        $permissions = Permission::select('id', 'name')->orderBy('id')->get();

        return response()->json([
            'status' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Replace the permissions granted by a role. The admin role always keeps every
     * permission, so it cannot be edited here.
     */
    public function updateRolePermissions(Request $request, $id)
    {
        $this->ensureAdmin($request);
        $role = Role::findOrFail($id);

        if ($role->name === 'admin') {
            throw ValidationException::withMessages(['role' => 'صلاحيات مدير النظام ثابتة ولا يمكن تعديلها.']);
        }

        $data = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', $role->guard_name)],
        ]);

        return DB::transaction(function () use ($request, $role, $data) {
            $before = $role->permissions()->pluck('name')->sort()->values()->all();
            $role->syncPermissions($data['permissions']);
            $after = $role->permissions()->pluck('name')->sort()->values()->all();

            activity('permissions')
                ->causedBy($request->user())
                ->performedOn($role)
                ->event('updated')
                ->withProperties([
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                ])
                ->log("تعديل صلاحيات الوظيفة {$role->name}");

            return response()->json([
                'status' => true,
                'data' => $role->load('permissions:id,name')->only('id', 'name', 'permissions'),
            ]);
        });
    }
}
