<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset permission cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create roles safely
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $operationsRole = Role::firstOrCreate(['name' => 'مدير تشغيل']);
        $dataEntryRole = Role::firstOrCreate(['name' => 'مدير تنفيذ']);
        $dataEntryRole = Role::firstOrCreate(['name' => 'مندوب تشغيل']);
        $dataEntryRole = Role::firstOrCreate(['name' => 'محاسب']);
        $accountManagerRole = Role::firstOrCreate(['name' => 'مدير حسابات']);
        $portSupervisorRole = Role::firstOrCreate(['name' => 'مشرف ميناء']);

        // System models
        $models = [
            'users',
            'vehicles',
            'drivers',
            'clients',
            'factories',
            'destinations',
            'shipping_lines',
            'ship_order_data',
            'operation_orders',
            'policies',
            'reports',
            'transport_receipts',
            'treasury',
            'order_receipts',
        ];

        // CRUD + extra system actions
        $actions = [
            'view_any',
            'view',
            'create',
            'update',
            'delete',
            'export',
            'print',
            'approve'
        ];

        $permissions = [];

        foreach ($models as $model) {
            foreach ($actions as $action) {

                $permission = Permission::firstOrCreate([
                    'name' => "{$action} {$model}"
                ]);

                $permissions[] = $permission;
            }
        }

        // Admin gets all permissions
        $adminRole->syncPermissions($permissions);

        // إيصالات ومصروفات الأذن: Account Managers and Port Supervisors may view and create.
        // Editing stays admin-only; cancelling is restricted to the admin role in the controller.
        foreach ([$accountManagerRole, $portSupervisorRole] as $role) {
            $role->givePermissionTo(['view_any order_receipts', 'view order_receipts', 'create order_receipts']);
        }

        // Account Managers may delete ship orders (receipt history still blocks deletion).
        $accountManagerRole->givePermissionTo('delete ship_order_data');

        // Create default admin user safely
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@alraya.com'],
            [
                'full_name' => 'Admin User',
                'user_name' => 'admin',
                'phone_number' => '1234567890',
                'password' => Hash::make('password123'),
            ]
        );

        // Assign admin role
        $adminUser->syncRoles([$adminRole]);
    }
}
