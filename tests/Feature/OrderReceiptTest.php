<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Destination;
use App\Models\OrderReceipt;
use App\Models\Policy;
use App\Models\ShipLineClient;
use App\Models\ShipOrderData;
use App\Models\ShippingLine;
use App\Models\TransportReceipt;
use App\Models\Treasury;
use App\Models\TreasuryDeduction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderReceiptTest extends TestCase
{
    private User $admin;

    private ShipOrderData $order;

    private Client $client;

    private Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->fixtures();
    }

    private function fixtures(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::create(['full_name' => 'Receipt Admin', 'user_name' => 'receipt-admin',
            'email' => 'receipt@example.test', 'password' => 'password', 'phone_number' => '01000000000']);
        Role::findOrCreate('admin', 'web');
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        $this->order = ShipOrderData::create(['order_number' => 'ORDER-1', 'order_type' => 'import', 'noloans' => 200]);
        $this->client = Client::create(['client_name' => 'Client One']);
        $line = ShippingLine::create(['shipping_line_name' => 'Line']);
        $destination = Destination::create(['destination_name' => 'Port']);
        ShipLineClient::create(['ship_order_data_id' => $this->order->id, 'client_id' => $this->client->id,
            'shipping_line_id' => $line->id, 'destination_id' => $destination->id]);
        $this->treasury = Treasury::create(['name' => 'Cash', 'balance' => '1000.00']);
        $this->order->treasuries()->attach($this->treasury);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['ship_order_id' => $this->order->id, 'client_id' => $this->client->id,
            'treasury_id' => $this->treasury->id, 'receipt_date' => '2026-10-10',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['category' => 'customs_clearance', 'amount' => '100.25']]], $overrides);
    }

    public function test_order_receipts_post_without_policies_and_retries_do_not_double_charge(): void
    {
        $payload = $this->payload();
        $id = $this->postJson('/api/order-receipts', $payload)->assertCreated()->assertJsonPath('data.total', '100.25')->json('data.id');
        $this->postJson('/api/order-receipts', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertEquals('899.75', $this->treasury->fresh()->balance);
        $this->assertEquals(1, TreasuryDeduction::count());
        $this->assertEquals(0, Policy::count());
        $payload['items'][0]['amount'] = '101.00';
        $this->postJson('/api/order-receipts', $payload)->assertStatus(409);
    }

    public function test_adjustments_and_cancellation_reconcile_once_and_keep_history(): void
    {
        $id = $this->postJson('/api/order-receipts', $this->payload())->assertCreated()->json('data.id');
        $items = ['items' => [['category' => 'port_receipts', 'amount' => '150.00']]];
        $this->patchJson('/api/order-receipts/'.$id, $items)->assertOk();
        $this->patchJson('/api/order-receipts/'.$id, $items)->assertOk();
        $this->assertEquals('850.00', $this->treasury->fresh()->balance);
        $this->patchJson('/api/order-receipts/'.$id, ['notes' => 'Receipt copy'])->assertOk()->assertJsonPath('data.total', '150.00');
        $this->postJson('/api/order-receipts/'.$id.'/cancel', ['reason' => 'Cancelled order'])->assertOk();
        $this->postJson('/api/order-receipts/'.$id.'/cancel', ['reason' => 'Retry'])->assertOk();
        $this->assertEquals('1000.00', $this->treasury->fresh()->balance);
        $this->assertEquals(3, TreasuryDeduction::count());
        $this->assertEquals(0, TreasuryDeduction::sum('amount'));
        $this->patchJson('/api/order-receipts/'.$id, $items)->assertStatus(409);
        $this->assertEquals(1, OrderReceipt::count());
    }

    public function test_insufficient_funds_and_invalid_items_roll_back_everything(): void
    {
        $this->postJson('/api/order-receipts', $this->payload(['items' => [['category' => 'port_receipts', 'amount' => '1000.01']]]))->assertUnprocessable();
        $this->postJson('/api/order-receipts', $this->payload(['items' => [['category' => 'other', 'amount' => '1.00']]]))->assertUnprocessable();
        $this->postJson('/api/order-receipts', $this->payload(['items' => [['category' => 'port_receipts', 'amount' => '1.001']]]))->assertUnprocessable();
        $this->postJson('/api/order-receipts', $this->payload(['policy_id' => 1]))->assertUnprocessable();
        $this->assertEquals(0, OrderReceipt::count());
        $this->assertEquals(0, TreasuryDeduction::count());
        $this->assertEquals('1000.00', $this->treasury->fresh()->balance);
    }

    public function test_statement_includes_order_receipts_in_net_and_excludes_cancelled(): void
    {
        $id = $this->postJson('/api/order-receipts', $this->payload())->assertCreated()->json('data.id');
        $this->getJson('/api/reports/client-account-statements?client_id='.$this->client->id)
            ->assertOk()->assertJsonPath('data.total_sum_order_receipts', '100.25')
            ->assertJsonPath('data.net_amount', 300.25)->assertJsonPath('data.total_orders_noloans', 200)
            ->assertJsonPath('data.ship_orders_details.0.order_receipts_sum', '100.25');
        $this->postJson('/api/order-receipts/'.$id.'/cancel', ['reason' => 'Cancelled'])->assertOk();
        $this->getJson('/api/reports/client-account-statements')->assertOk()
            ->assertJsonPath('data.total_sum_order_receipts', '0.00')->assertJsonPath('data.net_amount', 200);
    }

    public function test_expenses_are_allocated_to_the_selected_client_only(): void
    {
        $second = Client::create(['client_name' => 'Client Two']);
        $link = $this->order->shipLineClients()->first()->replicate();
        $link->client_id = $second->id;
        $link->save();
        $this->postJson('/api/order-receipts', $this->payload())->assertCreated();
        $this->postJson('/api/order-receipts', $this->payload(['client_id' => $second->id]))->assertCreated();
        $this->getJson('/api/reports/client-account-statements?client_id='.$second->id)->assertOk()
            ->assertJsonPath('data.total_sum_order_receipts', '100.25')->assertJsonCount(1, 'data.ship_orders_details.0.order_receipts');
        $this->getJson('/api/reports/client-account-statements')->assertOk()->assertJsonPath('data.total_sum_order_receipts', '200.50');
        $unrelated = Client::create(['client_name' => 'Unrelated']);
        $this->postJson('/api/order-receipts', $this->payload(['client_id' => $unrelated->id]))->assertUnprocessable();
    }

    public function test_original_treasury_is_used_after_order_reassignment_and_parent_delete_is_blocked(): void
    {
        $id = $this->postJson('/api/order-receipts', $this->payload())->assertCreated()->json('data.id');
        $other = Treasury::create(['name' => 'New cash', 'balance' => 500]);
        $this->order->treasuries()->sync([$other->id]);
        $this->postJson('/api/order-receipts/'.$id.'/cancel', ['reason' => 'Refund original'])->assertOk();
        $this->assertEquals(1000, $this->treasury->fresh()->balance);
        $this->assertEquals(500, $other->fresh()->balance);
        $this->deleteJson('/api/ship-order-data/'.$this->order->id)->assertStatus(409);
        $this->patchJson('/api/order-receipts/'.$id, ['treasury_id' => $other->id])->assertUnprocessable();
    }

    public function test_receipt_deductions_cannot_be_manipulated_independently(): void
    {
        $this->postJson('/api/order-receipts', $this->payload())->assertCreated();
        $id = TreasuryDeduction::first()->id;
        $this->postJson('/api/treasury/update/deduction/'.$id, ['treasury_id' => $this->treasury->id, 'amount' => 2, 'type' => 'expense'])->assertStatus(409);
        $this->postJson('/api/treasury/delete/deduction/'.$id)->assertStatus(409);
        $this->assertEquals('899.75', $this->treasury->fresh()->balance);
    }

    public function test_unauthorized_treasury_access_is_rejected_and_list_is_scoped(): void
    {
        $id = $this->postJson('/api/order-receipts', $this->payload())->assertCreated()->json('data.id');
        $user = User::create(['full_name' => 'Other', 'user_name' => 'other', 'email' => 'other@example.test', 'password' => 'password', 'phone_number' => '2']);
        // Has module access by role, but is not assigned to the receipt's treasury.
        $user->assignRole('مدير حسابات');
        $this->actingAs($user);
        $this->getJson('/api/order-receipts/'.$id)->assertForbidden();
        $this->getJson('/api/order-receipts')->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/order-receipts', $this->payload())->assertForbidden();
        $this->patchJson('/api/order-receipts/'.$id, ['notes' => 'Forbidden'])->assertForbidden();
        $this->postJson('/api/order-receipts/'.$id.'/cancel', ['reason' => 'Forbidden'])->assertForbidden();
    }

    private function userWithRole(?string $role, string $name): User
    {
        $user = User::create(['full_name' => $name, 'user_name' => $name, 'email' => $name.'@example.test',
            'password' => 'password', 'phone_number' => '3'.crc32($name)]);
        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }
        $user->treasuries()->attach($this->treasury);

        return $user;
    }

    public static function moduleRoles(): array
    {
        return ['account manager' => ['مدير حسابات'], 'port supervisor' => ['مشرف ميناء']];
    }

    /** @dataProvider moduleRoles */
    public function test_account_managers_and_supervisors_can_view_and_create_but_not_edit_or_cancel(string $role): void
    {
        $existing = $this->postJson('/api/order-receipts', $this->payload())->assertCreated()->json('data.id');
        $this->actingAs($this->userWithRole($role, 'module-user'));

        // Pages' API calls: permit list + permit details, categories, list, details, create.
        $this->getJson('/api/ship-order-data')->assertOk()->assertJsonPath('data.data.0.id', $this->order->id);
        $this->getJson('/api/ship-order-data/'.$this->order->id)->assertOk();
        $this->getJson('/api/order-receipts/categories')->assertOk();
        $this->getJson('/api/order-receipts')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/order-receipts/'.$existing)->assertOk();
        $created = $this->postJson('/api/order-receipts', $this->payload())->assertCreated()->json('data.id');
        $this->getJson('/api/order-receipts/'.$created)->assertOk();

        // Edit and cancel stay admin-only.
        $this->patchJson('/api/order-receipts/'.$created, ['notes' => 'x'])->assertForbidden();
        $this->postJson('/api/order-receipts/'.$created.'/cancel', ['reason' => 'x'])->assertForbidden();
        $this->assertEquals('799.50', $this->treasury->fresh()->balance);
    }

    public function test_unrelated_roles_are_denied_even_with_treasury_access(): void
    {
        $id = $this->postJson('/api/order-receipts', $this->payload())->assertCreated()->json('data.id');
        foreach (['مندوب تشغيل' => 'field-rep', null => 'no-role-user'] as $role => $name) {
            $this->actingAs($this->userWithRole($role ?: null, $name));
            $this->getJson('/api/order-receipts/categories')->assertForbidden();
            $this->getJson('/api/order-receipts')->assertForbidden();
            $this->getJson('/api/order-receipts/'.$id)->assertForbidden();
            $this->postJson('/api/order-receipts', $this->payload())->assertForbidden();
            $this->patchJson('/api/order-receipts/'.$id, ['notes' => 'x'])->assertForbidden();
            $this->postJson('/api/order-receipts/'.$id.'/cancel', ['reason' => 'x'])->assertForbidden();
        }
        $this->assertEquals('899.75', $this->treasury->fresh()->balance);
    }

    public function test_permission_migration_is_idempotent(): void
    {
        $migration = require base_path('database/migrations/2026_11_07_000002_grant_order_receipt_permissions.php');
        $migration->up();
        $this->seed(\Database\Seeders\RolesSeeder::class);
        $migration->up();
        $this->assertSame(1, Role::where('name', 'مشرف ميناء')->count());
        $this->assertSame(1, Role::where('name', 'مدير حسابات')->count());
        $names = \Spatie\Permission\Models\Permission::where('name', 'like', '% order_receipts')->pluck('name');
        $this->assertSame($names->count(), $names->unique()->count());
        $this->assertTrue($names->contains('update order_receipts'));
        $this->assertEqualsCanonicalizing(['view_any order_receipts', 'view order_receipts', 'create order_receipts'],
            Role::findByName('مشرف ميناء', 'web')->permissions->pluck('name')->all());
    }

    private function policy(): Policy
    {
        $operating = \App\Models\OperatingOrder::firstOrCreate(['ship_order_data_id' => $this->order->id]);

        return Policy::create(['operating_order_id' => $operating->id, 'ship_order_data_id' => $this->order->id, 'user_id' => $this->admin->id, 'policy_type' => false, 'covenant_amount' => 0]);
    }

    public function test_transport_requires_policy_and_updates_use_specific_receipt(): void
    {
        $this->postJson('/api/transport-receipts', ['ship_order_id' => $this->order->id, 'port_receipts' => 10])->assertUnprocessable();
        $policy = $this->policy();
        $payload = ['ship_order_id' => $this->order->id, 'policy_id' => $policy->id, 'port_receipts' => '10.25'];
        $id = $this->postJson('/api/transport-receipts', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/transport-receipts', $payload)->assertStatus(409);
        $second = $this->policy();
        $this->postJson('/api/transport-receipts', array_replace($payload, ['policy_id' => $second->id]))->assertCreated();
        $this->patchJson('/api/transport-receipts/'.$id, ['port_receipts' => '20.00'])->assertOk();
        $this->patchJson('/api/transport-receipts/'.$id, ['port_receipts' => '20.00'])->assertOk();
        $this->assertEquals('969.75', $this->treasury->fresh()->balance);
        $this->assertEquals(3, TreasuryDeduction::count());
        $this->deleteJson('/api/policies/'.$policy->id)->assertStatus(409);
        $this->deleteJson('/api/transport-receipts/'.$id, ['reason' => 'Cancelled'])->assertOk();
        $this->deleteJson('/api/transport-receipts/'.$id, ['reason' => 'Retry'])->assertOk();
        $this->assertEquals('989.75', $this->treasury->fresh()->balance);
        $this->assertEquals(2, TransportReceipt::count());
        $this->getJson('/api/reports/client-account-statements')->assertOk()->assertJsonPath('data.total_sum_transport_receipts', 10.25);
    }

    public function test_deleting_transport_receipt_refunds_treasury_hides_it_and_allows_reissue(): void
    {
        $policy = $this->policy();
        $payload = ['ship_order_id' => $this->order->id, 'policy_id' => $policy->id, 'port_receipts' => '40.50'];
        $id = $this->postJson('/api/transport-receipts', $payload)->assertCreated()->json('data.id');
        $this->assertEquals('959.50', $this->treasury->fresh()->balance);

        // Reason is optional; the delete reverses the treasury effect once.
        $this->deleteJson('/api/transport-receipts/'.$id)->assertOk();
        $this->deleteJson('/api/transport-receipts/'.$id)->assertOk();
        $this->assertEquals('1000.00', $this->treasury->fresh()->balance);
        $deductions = TreasuryDeduction::where('transport_receipt_id', $id)->orderBy('id')->get();
        $this->assertSame(['transport_receipt', 'transport_receipt_reversal'], $deductions->pluck('type')->all());
        $this->assertEquals(0, $deductions->sum('amount'));
        $this->assertSame('مسح الإيصال', TransportReceipt::find($id)->cancellation_reason);

        $this->getJson('/api/transport-receipts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/transport-receipts?include_cancelled=1')->assertOk()->assertJsonCount(1, 'data');

        // A new receipt can be issued for the policy after the old one was deleted.
        $this->postJson('/api/transport-receipts', array_replace($payload, ['port_receipts' => '15.00']))->assertCreated();
        $this->postJson('/api/transport-receipts', $payload)->assertStatus(409);
        $this->assertEquals('985.00', $this->treasury->fresh()->balance);
    }

    public function test_legacy_mapping_is_dry_run_safe_and_idempotent(): void
    {
        $receipt = TransportReceipt::create(['ship_order_id' => $this->order->id, 'policy_id' => $this->policy()->id, 'port_receipts' => '20.00']);
        $deduction = TreasuryDeduction::create(['user_id' => $this->admin->id, 'treasury_id' => $this->treasury->id,
            'amount' => 20, 'type' => 'transport_receipt', 'reason' => 'Legacy reviewed expense']);
        $file = tempnam(sys_get_temp_dir(), 'receipt-mapping');
        try {
            file_put_contents($file, json_encode([['receipt_id' => $receipt->id, 'treasury_id' => $this->treasury->id, 'deduction_ids' => [$deduction->id]]]));
            $this->artisan('receipts:reconcile', ['mapping' => $file])->assertSuccessful();
            $this->assertNull($receipt->fresh()->treasury_id);
            $this->artisan('receipts:reconcile', ['mapping' => $file, '--apply' => true])->assertSuccessful();
            $this->artisan('receipts:reconcile', ['mapping' => $file, '--apply' => true])->assertSuccessful();
            $this->assertEquals($receipt->id, $deduction->fresh()->transport_receipt_id);
            $this->assertEquals('1000.00', $this->treasury->fresh()->balance);
            $this->assertEquals(1, TreasuryDeduction::count());
        } finally {
            unlink($file);
        }
    }

    public function test_legacy_unresolved_transport_cannot_be_edited_and_settled_policy_is_protected(): void
    {
        $policy = $this->policy();
        $legacy = TransportReceipt::create(['ship_order_id' => $this->order->id, 'policy_id' => $policy->id, 'port_receipts' => '20.00']);
        $this->patchJson('/api/transport-receipts/'.$legacy->id, ['port_receipts' => '30.00'])->assertStatus(409);
        $this->deleteJson('/api/transport-receipts/'.$legacy->id, ['reason' => 'Unresolved'])->assertStatus(409);
        $other = ShipOrderData::create(['order_number' => 'OTHER', 'order_type' => 'import']);
        $this->patchJson('/api/policies/'.$policy->id, ['ship_order_data_id' => $other->id])->assertStatus(409);
        $second = $this->policy()->fresh();
        $second->update(['settled' => true]);
        $this->postJson('/api/transport-receipts', ['ship_order_id' => $this->order->id, 'policy_id' => $second->id, 'port_receipts' => 10])->assertStatus(409);
        $this->assertEquals(1000, $this->treasury->fresh()->balance);
    }

    public function test_parent_deletion_is_protected_at_database_level(): void
    {
        $this->postJson('/api/order-receipts', $this->payload())->assertCreated();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('ship_order_data')->where('id', $this->order->id)->delete();
    }

    public function test_upgrade_preserves_existing_financial_data(): void
    {
        // Build the old schema in a separate in-memory database; never touch the configured app database.
        DB::purge('sqlite');
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => ! str_contains($p, '2026_10_10_')));
        Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]);
        $this->fixtures();
        $legacy = TransportReceipt::create(['ship_order_id' => $this->order->id, 'policy_id' => $this->policy()->id, 'port_receipts' => '20.00']);
        TreasuryDeduction::create(['user_id' => $this->admin->id, 'treasury_id' => $this->treasury->id,
            'amount' => 20, 'type' => 'transport_receipt', 'reason' => 'Legacy']);
        $this->treasury->update(['balance' => '980.00']);
        Artisan::call('migrate', ['--force' => true]);
        $this->assertEquals('980.00', $this->treasury->fresh()->balance);
        $this->assertEquals(20, $legacy->fresh()->port_receipts);
        $this->assertNull($legacy->fresh()->treasury_id);
        $this->assertEquals(1, TreasuryDeduction::count());
        $this->assertEquals(0, OrderReceipt::count());
    }

    public function test_null_policy_legacy_conversion_preserves_items_and_does_not_post_cash_again(): void
    {
        // Simulate an older deployed schema that allowed null policy IDs (fresh schema forbids them).
        \Illuminate\Support\Facades\Schema::drop('transport_receipts');
        \Illuminate\Support\Facades\Schema::create('transport_receipts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('ship_order_id');
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->unsignedBigInteger('treasury_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('migrated_to_order_receipt_at')->nullable();
            $table->timestamps();
            foreach (TransportReceipt::EXPENSE_FIELDS as $field) {
                $table->decimal($field)->nullable();
            }
        });
        $legacy = TransportReceipt::create(['ship_order_id' => $this->order->id, 'port_receipts' => '20.00', 'tips' => '1.25']);
        $deduction = TreasuryDeduction::create(['user_id' => $this->admin->id, 'treasury_id' => $this->treasury->id,
            'amount' => '21.25', 'type' => 'transport_receipt', 'reason' => 'Legacy']);
        $file = tempnam(sys_get_temp_dir(), 'receipt-mapping');
        try {
            file_put_contents($file, json_encode([['receipt_id' => $legacy->id, 'treasury_id' => $this->treasury->id,
                'client_id' => $this->client->id, 'deduction_ids' => [$deduction->id]]]));
            $this->artisan('receipts:reconcile', ['mapping' => $file])->assertSuccessful();
            $this->assertEquals(0, OrderReceipt::count());
            $this->artisan('receipts:reconcile', ['mapping' => $file, '--apply' => true])->assertSuccessful();
            $this->artisan('receipts:reconcile', ['mapping' => $file, '--apply' => true])->assertSuccessful();
            $converted = OrderReceipt::first();
            $this->assertEquals('21.25', $converted->total);
            $this->assertEquals(2, $converted->items()->count());
            $this->assertNotNull($legacy->fresh()->migrated_to_order_receipt_at);
            $this->assertEquals(1, TreasuryDeduction::count());
            $this->assertEquals('1000.00', $this->treasury->fresh()->balance);
            $this->getJson('/api/reports/client-account-statements')->assertOk()
                ->assertJsonPath('data.total_sum_order_receipts', '21.25')->assertJsonPath('data.total_sum_transport_receipts', 0);
        } finally {
            unlink($file);
        }
    }

    public function test_existing_treasury_operations_preserve_receipt_balances(): void
    {
        $this->treasury->update(['is_main' => true]);
        $other = Treasury::create(['name' => 'Sub cash', 'balance' => '0.00']);
        $this->postJson('/api/order-receipts', $this->payload())->assertCreated();
        $this->postJson('/api/treasury/deposit', ['amount' => '10.25'])->assertCreated();
        $this->postJson('/api/treasury/send', ['receivable_id' => $other->id, 'amount' => '20.00'])->assertCreated();
        $this->postJson('/api/treasury/deduction', ['treasury_id' => $this->treasury->id, 'amount' => '5.00', 'type' => 'expense'])->assertCreated();
        $this->postJson('/api/treasury/send', ['receivable_id' => $other->id, 'amount' => '1000.00'])->assertStatus(400);
        $this->postJson('/api/treasury/deduction', ['treasury_id' => $this->treasury->id, 'amount' => '1000.00', 'type' => 'expense'])->assertStatus(400);
        $this->assertEquals('885.00', $this->treasury->fresh()->balance);
        $this->assertEquals('20.00', $other->fresh()->balance);
        $this->assertEquals(0, DB::transactionLevel());
    }
}
