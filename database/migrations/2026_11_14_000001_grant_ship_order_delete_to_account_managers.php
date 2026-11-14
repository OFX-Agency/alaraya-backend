<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Allow Account Managers (مدير حسابات) to delete ship orders through the
 * existing "delete ship_order_data" permission. Idempotent; admins keep their
 * role-based access. Orders with receipt history remain undeletable.
 */
return new class extends Migration
{
    private const PERMISSION = 'delete ship_order_data';

    private const ROLE = 'مدير حسابات';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::PERMISSION, 'web');
        Role::findOrCreate(self::ROLE, 'web')->givePermissionTo(self::PERMISSION);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Only revoke the grant; the permission itself belongs to RolesSeeder.
        Role::where('name', self::ROLE)->where('guard_name', 'web')->first()?->revokePermissionTo(self::PERMISSION);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
