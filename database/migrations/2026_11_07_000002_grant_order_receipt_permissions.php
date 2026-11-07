<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Access to the "إيصالات ومصروفات الأذن" (order receipts) module.
 *
 * Follows the RolesSeeder "{action} {model}" permission naming. Idempotent:
 * safe to run on fresh installs and on existing environments, and re-running
 * never duplicates permissions or role assignments.
 *
 * - admin:          view_any, view, create, update, delete (cancel itself stays admin-role only)
 * - مدير حسابات:     view_any, view, create   (Account Managers)
 * - مشرف ميناء:      view_any, view, create   (Port Supervisors)
 */
return new class extends Migration
{
    private const GUARD = 'web';

    private const ALL = ['view_any', 'view', 'create', 'update', 'delete'];

    private const MODULE_ROLES = ['مدير حسابات', 'مشرف ميناء'];

    private const MODULE_ACTIONS = ['view_any', 'view', 'create'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ALL as $action) {
            Permission::findOrCreate("{$action} order_receipts", self::GUARD);
        }

        if ($admin = Role::where('name', 'admin')->where('guard_name', self::GUARD)->first()) {
            $admin->givePermissionTo(array_map(fn ($a) => "{$a} order_receipts", self::ALL));
        }

        foreach (self::MODULE_ROLES as $roleName) {
            Role::findOrCreate($roleName, self::GUARD)
                ->givePermissionTo(array_map(fn ($a) => "{$a} order_receipts", self::MODULE_ACTIONS));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Removing the permissions also removes their role/user assignments. Roles are kept.
        Permission::where('guard_name', self::GUARD)
            ->whereIn('name', array_map(fn ($a) => "{$a} order_receipts", self::ALL))
            ->get()
            ->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
