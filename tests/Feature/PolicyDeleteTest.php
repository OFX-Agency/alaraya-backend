<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Destination;
use App\Models\OperatingOrder;
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
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PolicyDeleteTest extends TestCase
{
    private User $admin;

    private ShipOrderData $order;

    private Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::create(['full_name' => 'Admin', 'user_name' => 'policy-admin', 'email' => 'policy-admin@example.test',
            'password' => 'password', 'phone_number' => '01000000099']);
        Role::findOrCreate('admin', 'web');
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        $this->order = ShipOrderData::create(['order_number' => 'ORD-P', 'order_type' => 'import', 'noloans' => 100, 'transfers_count' => 5]);
        $client = Client::create(['client_name' => 'Client']);
        ShipLineClient::create(['ship_order_data_id' => $this->order->id, 'client_id' => $client->id,
            'shipping_line_id' => ShippingLine::create(['shipping_line_name' => 'Line'])->id,
            'destination_id' => Destination::create(['destination_name' => 'Port'])->id]);
        $this->treasury = Treasury::create(['name' => 'Cash', 'balance' => '1000.00']);
        $this->order->treasuries()->attach($this->treasury);
    }

    private function policy(array $attributes = []): Policy
    {
        $operating = OperatingOrder::firstOrCreate(['ship_order_data_id' => $this->order->id]);

        return Policy::create($attributes + ['operating_order_id' => $operating->id, 'ship_order_data_id' => $this->order->id,
            'user_id' => $this->admin->id, 'policy_type' => false, 'covenant_amount' => 0]);
    }

    private function receipt(Policy $policy, string $amount): int
    {
        return $this->postJson('/api/transport-receipts', ['ship_order_id' => $this->order->id, 'policy_id' => $policy->id,
            'port_receipts' => $amount])->assertCreated()->json('data.id');
    }

    public function test_deleting_a_policy_reverses_and_removes_its_receipts(): void
    {
        $policy = $this->policy();
        $receiptId = $this->receipt($policy, '120.50');
        $this->assertEquals('879.50', $this->treasury->fresh()->balance);

        $this->deleteJson('/api/policies/'.$policy->id)->assertOk()
            ->assertJsonPath('reversed_receipts', 1)
            ->assertJsonPath('reversed_amount', '120.50');

        // Treasury is back to its balance, with a linked reversal entry (history is kept).
        $this->assertEquals('1000.00', $this->treasury->fresh()->balance);
        $deductions = TreasuryDeduction::where('transport_receipt_id', $receiptId)->orderBy('id')->get();
        $this->assertSame(['transport_receipt', 'transport_receipt_reversal'], $deductions->pluck('type')->all());
        $this->assertEquals(0, $deductions->sum('amount'));

        // Receipt is cancelled and no longer listed; the policy is soft-deleted.
        $receipt = TransportReceipt::find($receiptId);
        $this->assertNotNull($receipt->cancelled_at);
        $this->assertStringContainsString('حذف البوليصة', $receipt->cancellation_reason);
        $this->getJson('/api/transport-receipts')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSoftDeleted('policies', ['id' => $policy->id]);
    }

    public function test_index_and_show_only_return_policies_that_are_not_deleted(): void
    {
        $kept = $this->policy();
        $deleted = $this->policy();
        $this->deleteJson('/api/policies/'.$deleted->id)->assertOk();

        $this->getJson('/api/policies')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $kept->id);
        $this->getJson('/api/policies?search='.$deleted->policy_number)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/policies/'.$deleted->id)->assertNotFound();
        $this->getJson('/api/ship-order-data/'.$this->order->id.'/policies')->assertOk();
        $this->assertSame(1, $this->order->policies()->count());

        // Deleting it again is a clear 404, not a server error.
        $this->deleteJson('/api/policies/'.$deleted->id)->assertNotFound()
            ->assertJsonPath('message', 'البوليصة غير موجودة أو تم حذفها بالفعل.');
    }

    public function test_deleted_policy_number_is_never_reused(): void
    {
        $first = $this->policy();
        $last = $this->policy();
        $this->deleteJson('/api/policies/'.$last->id)->assertOk();

        $next = $this->getJson('/api/policy-number')->assertOk()->json('policy_number');
        $this->assertNotSame($last->policy_number, $next);
        $this->assertNotSame($first->policy_number, $next);
    }

    public function test_settled_policy_with_receipts_is_rejected_without_changes(): void
    {
        $policy = $this->policy();
        $receiptId = $this->receipt($policy, '50.00');
        // A model fresh from create() lacks the settled_user column, which clashes with the
        // settled_user() relation in the activity log, so mark it settled directly.
        DB::table('policies')->where('id', $policy->id)->update(['settled' => true]);

        $this->deleteJson('/api/policies/'.$policy->id)->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن حذف بوليصة تمت تصفيتها ولها إيصالات نقل.');

        $this->assertEquals('950.00', $this->treasury->fresh()->balance);
        $this->assertNull(TransportReceipt::find($receiptId)->cancelled_at);
        $this->assertNotSoftDeleted('policies', ['id' => $policy->id]);
    }

    public function test_legacy_receipt_without_treasury_is_rejected_without_changes(): void
    {
        $policy = $this->policy();
        $legacy = TransportReceipt::create(['ship_order_id' => $this->order->id, 'policy_id' => $policy->id, 'port_receipts' => 30]);

        $this->deleteJson('/api/policies/'.$policy->id)->assertStatus(409);
        $this->assertNull($legacy->fresh()->cancelled_at);
        $this->assertNotSoftDeleted('policies', ['id' => $policy->id]);
        $this->assertEquals('1000.00', $this->treasury->fresh()->balance);
    }

    public function test_already_cancelled_receipts_are_not_reversed_twice(): void
    {
        $policy = $this->policy();
        $receiptId = $this->receipt($policy, '40.00');
        $this->deleteJson('/api/transport-receipts/'.$receiptId)->assertOk();
        $this->assertEquals('1000.00', $this->treasury->fresh()->balance);

        $this->deleteJson('/api/policies/'.$policy->id)->assertOk()->assertJsonPath('reversed_receipts', 0);
        $this->assertEquals('1000.00', $this->treasury->fresh()->balance);
    }

    public function test_non_admins_cannot_delete_policies(): void
    {
        $policy = $this->policy();
        $user = User::create(['full_name' => 'Acc', 'user_name' => 'acc', 'email' => 'acc@example.test',
            'password' => 'password', 'phone_number' => '01000000098']);
        $user->assignRole(Role::findOrCreate('مدير حسابات', 'web'));

        $this->actingAs($user)->deleteJson('/api/policies/'.$policy->id)->assertForbidden();
        $this->assertNotSoftDeleted('policies', ['id' => $policy->id]);
    }
}
