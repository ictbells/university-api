<?php

namespace Tests\Feature;

use App\Mail\PublicPayRequestPaidMail;
use App\Mail\PublicPayRequestReadyMail;
use App\Mail\PublicPayRequestRejectedMail;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\Permission;
use App\Models\Program;
use App\Models\PublicPayOffer;
use App\Models\PublicPayRequest;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\PublicPaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicPayRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $studentUser;

    private Student $student;

    private User $staffUser;

    private FeeItem $fee;

    private PublicPayOffer $offer;

    private const NIN = '12345678901';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }

        config(['services.paystack.allow_demo_fulfill' => true, 'services.paystack.secret' => null]);

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $faculty = Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'Science']);
        $department = Department::query()->create(['faculty_id' => $faculty->id, 'name' => 'CS']);
        $program = Program::query()->create([
            'department_id' => $department->id,
            'name' => 'B.Sc CS',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);

        $this->studentUser = User::factory()->create([
            'email' => 'alumni@example.com',
            'status' => 'active',
        ]);
        $this->student = Student::query()->create([
            'user_id' => $this->studentUser->id,
            'program_id' => $program->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => 'BUT/2018/0001',
            'student_number' => 'STU001',
            'current_level' => 400,
            'nin' => self::NIN,
            'status' => 'active',
        ]);

        $role = Role::query()->create(['name' => 'Public pay', 'slug' => 'public-pay']);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', [
                'public_pay.view',
                'public_pay.process',
                'public_pay.offers',
            ])->pluck('id'),
        );
        $this->staffUser = User::factory()->create(['status' => 'active']);
        $this->staffUser->roles()->attach($role->id);

        $this->fee = FeeItem::query()->create([
            'name' => 'Letter of attestation',
            'category' => 'sundry',
            'amount' => 7500,
            'wallet_allowed' => true,
            'is_required' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $this->offer = PublicPayOffer::query()->create([
            'name' => 'Letter of attestation',
            'slug' => 'letter-of-attestation',
            'description' => 'Official letter confirming studentship.',
            'fee_item_id' => $this->fee->id,
            'is_active' => true,
            'display_order' => 1,
        ]);

        PublicPaySettings::update([
            'public_pay_enabled' => true,
        ]);
    }

    public function test_meta_disabled_without_offers_or_setting(): void
    {
        PublicPaySettings::update(['public_pay_enabled' => false]);
        $this->getJson('/api/public-pay/meta')
            ->assertOk()
            ->assertJsonPath('enabled', false);

        PublicPaySettings::update(['public_pay_enabled' => true]);
        $this->offer->update(['is_active' => false]);
        $this->getJson('/api/public-pay/meta')
            ->assertOk()
            ->assertJsonPath('enabled', false);
    }

    public function test_meta_lists_active_offers_when_enabled(): void
    {
        $this->getJson('/api/public-pay/meta')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('offers.0.name', 'Letter of attestation')
            ->assertJsonPath('offers.0.fee.amount', 7500);
    }

    public function test_lookup_and_create_pay_marks_paid(): void
    {
        Mail::fake();

        $this->postJson('/api/public-pay/lookup', ['nin' => self::NIN])
            ->assertOk()
            ->assertJsonPath('student.matric_number', 'BUT/2018/0001');

        $create = $this->postJson('/api/public-pay', [
            'nin' => self::NIN,
            'offer_id' => $this->offer->id,
            'purpose' => 'Job application',
            'contact_email' => 'alumni@example.com',
        ])->assertCreated();

        $token = $create->json('request.token');
        $reference = $create->json('payment.reference');
        $this->assertNotEmpty($token);
        $this->assertTrue((bool) $create->json('payment.demo'));

        $this->getJson('/api/public-pay/'.$token.'/verify/'.$reference)
            ->assertOk()
            ->assertJsonPath('request.status', 'paid');

        $this->assertDatabaseHas('public_pay_requests', [
            'public_token' => $token,
            'status' => 'paid',
            'offer_id' => $this->offer->id,
        ]);

        Mail::assertSent(PublicPayRequestPaidMail::class);

        $request = PublicPayRequest::query()->where('public_token', $token)->firstOrFail();
        $this->assertSame('sundry', $request->invoice?->category);
    }

    public function test_sundry_invoice_without_public_pay_cannot_pay_online(): void
    {
        Sanctum::actingAs($this->studentUser);
        $invoice = app(\App\Services\InvoiceService::class)->createForFee(
            $this->studentUser,
            $this->fee,
            null,
            $this->student->id,
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\PaymentFulfillmentService::class)->assertInvoicePayable($invoice);
    }

    public function test_staff_offer_crud_and_fulfillment(): void
    {
        Mail::fake();
        Storage::fake(config('filesystems.default', 'local'));

        Sanctum::actingAs($this->staffUser);

        $created = $this->postJson('/api/staff/public-pay/offers', [
            'name' => 'Statement of result',
            'fee_item_id' => $this->fee->id,
            'is_active' => true,
        ])->assertCreated()
            ->json();

        $this->assertSame('statement-of-result', $created['slug']);

        $this->putJson('/api/staff/public-pay/offers/'.$created['id'], [
            'description' => 'Updated',
        ])->assertOk()
            ->assertJsonPath('description', 'Updated');

        Sanctum::actingAs($this->studentUser); // clear for public create
        // Public create does not use sanctum; re-auth staff later.
        $create = $this->postJson('/api/public-pay', [
            'nin' => self::NIN,
            'offer_id' => $this->offer->id,
        ])->assertCreated();
        $token = $create->json('request.token');
        $this->getJson('/api/public-pay/'.$token.'/verify/'.$create->json('payment.reference'))->assertOk();

        $request = PublicPayRequest::query()->where('public_token', $token)->firstOrFail();

        Sanctum::actingAs($this->staffUser);
        $this->postJson('/api/staff/public-pay/requests/'.$request->id.'/start')
            ->assertOk()
            ->assertJsonPath('status', 'processing');

        $file = UploadedFile::fake()->create('letter.pdf', 100, 'application/pdf');
        $this->post('/api/staff/public-pay/requests/'.$request->id.'/ready', [
            'delivery_mode' => 'uploaded',
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('status', 'ready');

        Mail::assertSent(PublicPayRequestReadyMail::class);

        $this->get('/api/public-pay/'.$token.'/download')->assertOk();

        $create2 = $this->postJson('/api/public-pay', [
            'nin' => self::NIN,
            'offer_id' => $this->offer->id,
        ])->assertCreated();
        $token2 = $create2->json('request.token');
        $this->getJson('/api/public-pay/'.$token2.'/verify/'.$create2->json('payment.reference'))->assertOk();
        $request2 = PublicPayRequest::query()->where('public_token', $token2)->firstOrFail();

        $this->postJson('/api/staff/public-pay/requests/'.$request2->id.'/reject', [
            'reason' => 'Incomplete documentation provided',
        ])->assertOk()
            ->assertJsonPath('status', 'rejected');

        Mail::assertSent(PublicPayRequestRejectedMail::class);
    }

    public function test_create_rejects_unknown_nin(): void
    {
        $this->postJson('/api/public-pay', [
            'nin' => '99999999999',
            'offer_id' => $this->offer->id,
        ])->assertStatus(422);
    }
}
