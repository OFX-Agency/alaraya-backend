<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserPermissionManagementTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(\Database\Seeders\RolesSeeder::class);
        $this->admin = User::where('email', 'admin@alraya.com')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function makeUser(string $name, ?string $role = null): User
    {
        $user = User::create(['full_name' => $name, 'user_name' => $name, 'email' => "{$name}@example.test",
            'password' => 'password', 'phone_number' => '01'.str_pad((string) crc32($name), 9, '0')]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_admin_can_assign_direct_permissions_on_create_and_edit(): void
    {
        $id = $this->postJson('/api/auth/users', [
            'full_name' => 'Accountant', 'user_name' => 'acc', 'email' => 'acc@example.test',
            'password' => 'password123', 'phone_number' => '01000000011', 'role' => 'محاسب',
            'permissions' => ['view_any order_receipts', 'view order_receipts'],
        ])->assertCreated()
            ->assertJsonPath('user.roles.0.name', 'محاسب')
            ->assertJsonCount(2, 'user.permissions')
            ->json('user.id');

        $this->putJson("/api/auth/users/{$id}", ['permissions' => ['create order_receipts']])
            ->assertOk()
            ->assertJsonCount(1, 'user.permissions')
            ->assertJsonPath('user.permissions.0.name', 'create order_receipts');

        $user = User::find($id);
        $this->assertTrue($user->can('create order_receipts'));
        $this->assertFalse($user->can('view order_receipts'));

        // Editing other fields without "permissions" keeps them.
        $this->putJson("/api/auth/users/{$id}", ['full_name' => 'Renamed'])->assertOk();
        $this->assertTrue(User::find($id)->can('create order_receipts'));

        // Empty array clears direct permissions.
        $this->putJson("/api/auth/users/{$id}", ['permissions' => []])->assertOk()->assertJsonCount(0, 'user.permissions');

        $this->getJson('/api/auth/users')->assertOk()->assertJsonStructure(['users' => [['roles', 'permissions', 'treasuries']]]);
        // create, change, clear: each permission change is audited (the rename is not).
        $this->assertSame(3, Activity::where('log_name', 'permissions')->where('subject_id', $id)->count());
    }

    public function test_invalid_permission_or_role_is_rejected(): void
    {
        $user = $this->makeUser('target', 'محاسب');
        $this->putJson("/api/auth/users/{$user->id}", ['permissions' => ['fly to moon']])->assertUnprocessable();
        $this->putJson("/api/auth/users/{$user->id}", ['role' => 'not-a-role'])->assertUnprocessable();
    }

    public function test_admin_can_edit_role_permissions_and_it_takes_effect(): void
    {
        $role = Role::findByName('محاسب', 'web');
        $member = $this->makeUser('member', 'محاسب');
        $this->assertFalse($member->can('view_any order_receipts'));

        $roles = collect($this->getJson('/api/auth/roles-permissions/roles')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'permissions' => [['id', 'name']]]]])
            ->json('data'))->keyBy('name');
        // Each role comes back with the permissions it currently grants (shown checked in the editor).
        $this->assertEqualsCanonicalizing(['view_any order_receipts', 'view order_receipts', 'create order_receipts'],
            collect($roles['مشرف ميناء']['permissions'])->pluck('name')->all());
        $this->assertCount(\Spatie\Permission\Models\Permission::count(), $roles['admin']['permissions']);

        $this->putJson("/api/auth/roles-permissions/roles/{$role->id}/permissions", [
            'permissions' => ['view_any order_receipts', 'view order_receipts', 'create order_receipts'],
        ])->assertOk()->assertJsonCount(3, 'data.permissions');

        $this->assertTrue(User::find($member->id)->can('view_any order_receipts'));
        $this->assertEqualsCanonicalizing(['view_any order_receipts', 'view order_receipts', 'create order_receipts'],
            Activity::where('log_name', 'permissions')->latest('id')->first()->properties['added']);

        // The new role permission is enforced by the API it guards.
        $this->actingAs(User::find($member->id))->getJson('/api/order-receipts')->assertOk();

        // And can be removed again.
        $this->actingAs($this->admin)
            ->putJson("/api/auth/roles-permissions/roles/{$role->id}/permissions", ['permissions' => []])
            ->assertOk();
        $this->actingAs(User::find($member->id))->getJson('/api/order-receipts')->assertForbidden();
    }

    public function test_effective_permissions_are_returned_for_the_current_user(): void
    {
        $user = $this->makeUser('supervisor', 'مشرف ميناء');
        $user->givePermissionTo('update order_receipts');
        $this->actingAs($user)->getJson('/api/auth/user')->assertOk()
            ->assertJsonPath('all_permissions', fn ($names) => collect($names)->sort()->values()->all() === [
                'create order_receipts', 'update order_receipts', 'view order_receipts', 'view_any order_receipts']);
    }

    public function test_admin_role_permissions_are_locked_and_admin_cannot_lock_themselves_out(): void
    {
        $adminRole = Role::findByName('admin', 'web');
        $this->putJson("/api/auth/roles-permissions/roles/{$adminRole->id}/permissions", ['permissions' => []])
            ->assertUnprocessable();
        $this->putJson("/api/auth/users/{$this->admin->id}", ['role' => 'محاسب'])->assertUnprocessable();
        $this->deleteJson("/api/auth/users/{$this->admin->id}")->assertUnprocessable();
        $this->assertTrue($this->admin->fresh()->hasRole('admin'));
    }

    public function test_non_admins_cannot_manage_users_roles_or_permissions(): void
    {
        $victim = $this->makeUser('victim', 'محاسب');
        $role = Role::findByName('محاسب', 'web');

        foreach (['مدير حسابات', 'مشرف ميناء', 'مندوب تشغيل'] as $index => $roleName) {
            $this->actingAs($this->makeUser("actor{$index}", $roleName));
            $this->getJson('/api/auth/users')->assertForbidden();
            $this->postJson('/api/auth/users', ['full_name' => 'x', 'user_name' => "x{$index}", 'email' => "x{$index}@e.test",
                'password' => 'password123', 'phone_number' => '1', 'role' => 'admin'])->assertForbidden();
            $this->putJson("/api/auth/users/{$victim->id}", ['role' => 'admin'])->assertForbidden();
            $this->deleteJson("/api/auth/users/{$victim->id}")->assertForbidden();
            $this->getJson('/api/auth/roles-permissions/roles')->assertForbidden();
            $this->getJson('/api/auth/roles-permissions/permissions')->assertForbidden();
            $this->putJson("/api/auth/roles-permissions/roles/{$role->id}/permissions", ['permissions' => []])->assertForbidden();
        }

        $this->assertFalse($victim->fresh()->hasRole('admin'));
        $this->assertSame(0, User::where('email', 'like', 'x%@e.test')->count());
    }
}
