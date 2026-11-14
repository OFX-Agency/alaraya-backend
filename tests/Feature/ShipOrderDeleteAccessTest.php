<?php

namespace Tests\Feature;

use App\Models\ShipOrderData;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShipOrderDeleteAccessTest extends TestCase
{
    private Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(\Database\Seeders\RolesSeeder::class);
        $this->treasury = Treasury::create(['name' => 'Main', 'balance' => '0.00']);
    }

    private function user(string $name, string $role, bool $inTreasury = true): User
    {
        $user = User::create(['full_name' => $name, 'user_name' => $name, 'email' => "{$name}@example.test",
            'password' => 'password', 'phone_number' => '01'.str_pad((string) crc32($name), 9, '0')]);
        $user->assignRole($role);
        if ($inTreasury) {
            $user->treasuries()->attach($this->treasury);
        }

        return $user;
    }

    private function order(string $number): ShipOrderData
    {
        $order = ShipOrderData::create(['order_number' => $number, 'order_type' => 'import', 'noloans' => 0]);
        $order->treasuries()->attach($this->treasury);

        return $order;
    }

    public function test_admin_and_account_manager_can_delete_ship_orders(): void
    {
        $admin = User::where('email', 'admin@alraya.com')->firstOrFail();
        $this->actingAs($admin)->deleteJson('/api/ship-order-data/'.$this->order('ORD-A')->id)->assertOk();

        $manager = $this->user('manager', 'مدير حسابات');
        $this->assertTrue($manager->can('delete ship_order_data'));
        $order = $this->order('ORD-M');
        $this->actingAs($manager)->deleteJson('/api/ship-order-data/'.$order->id)->assertOk();
        $this->assertNull(ShipOrderData::find($order->id));
    }

    public function test_account_manager_cannot_delete_orders_outside_their_treasuries(): void
    {
        $manager = $this->user('outsider', 'مدير حسابات', inTreasury: false);
        $order = $this->order('ORD-X');
        $this->actingAs($manager)->deleteJson('/api/ship-order-data/'.$order->id)->assertForbidden();
        $this->assertNotNull(ShipOrderData::find($order->id));
    }

    public function test_other_roles_cannot_delete_ship_orders(): void
    {
        $order = $this->order('ORD-O');
        foreach (['محاسب', 'مدير تشغيل', 'مدير تنفيذ', 'مندوب تشغيل', 'مشرف ميناء'] as $index => $role) {
            $this->actingAs($this->user("other{$index}", $role))
                ->deleteJson('/api/ship-order-data/'.$order->id)->assertForbidden();
        }
        $this->assertNotNull(ShipOrderData::find($order->id));
    }

    public function test_receipt_history_still_blocks_deletion_for_account_managers(): void
    {
        $order = $this->order('ORD-R');
        $client = \App\Models\Client::create(['client_name' => 'C']);
        $line = \App\Models\ShippingLine::create(['shipping_line_name' => 'L']);
        $destination = \App\Models\Destination::create(['destination_name' => 'D']);
        \App\Models\ShipLineClient::create(['ship_order_data_id' => $order->id, 'client_id' => $client->id,
            'shipping_line_id' => $line->id, 'destination_id' => $destination->id]);
        $admin = User::where('email', 'admin@alraya.com')->firstOrFail();
        \App\Models\OrderReceipt::create(['receipt_number' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'ship_order_id' => $order->id, 'client_id' => $client->id, 'treasury_id' => $this->treasury->id,
            'created_by' => $admin->id, 'receipt_date' => '2026-11-14', 'total' => '1.00']);

        $this->actingAs($this->user('manager2', 'مدير حسابات'))
            ->deleteJson('/api/ship-order-data/'.$order->id)->assertStatus(409);
    }

    public function test_driver_additions_edit_and_delete_are_admin_only_like_the_ui(): void
    {
        foreach (['مدير حسابات', 'مدير تشغيل'] as $index => $role) {
            $this->actingAs($this->user("extras{$index}", $role));
            $this->putJson('/api/driver-extras/1', ['extra_amount' => 5])->assertForbidden();
            $this->deleteJson('/api/driver-extras/1')->assertForbidden();
        }
    }

    public function test_ship_order_delete_migration_is_idempotent(): void
    {
        $migration = require base_path('database/migrations/2026_11_14_000001_grant_ship_order_delete_to_account_managers.php');
        $migration->up();
        $migration->up();
        $role = \Spatie\Permission\Models\Role::findByName('مدير حسابات', 'web');
        $this->assertSame(1, $role->permissions()->where('name', 'delete ship_order_data')->count());
        $this->assertSame(1, \Spatie\Permission\Models\Permission::where('name', 'delete ship_order_data')->count());
    }
}
