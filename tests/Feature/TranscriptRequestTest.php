<?php

namespace Tests\Feature;

use App\Mail\TranscriptRequestPaidMail;
use App\Mail\TranscriptRequestReadyMail;
use App\Mail\TranscriptRequestRejectedMail;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeeItem;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Student;
use App\Models\TranscriptRequest;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\TranscriptRequestSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TranscriptRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $studentUser;

    private Student $student;

    private User $staffUser;

    private Program $program;

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
        $this->program = Program::query()->create([
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
            'program_id' => $this->program->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'matric_number' => 'BUT/2018/0001',
            'student_number' => 'STU001',
            'current_level' => 400,
            'status' => 'active',
        ]);

        $role = Role::query()->create(['name' => 'Registry', 'slug' => 'registry-transcript']);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['transcripts.view', 'transcripts.process'])->pluck('id'),
        );
        $this->staffUser = User::factory()->create(['status' => 'active']);
        $this->staffUser->roles()->attach($role->id);
        $office = OfficeDepartment::query()->create([
            'name' => 'Registry',
            'code' => 'REG-TR',
            'is_active' => true,
        ]);
        $office->syncNavKeys(['transcript-undergraduate', 'transcript-jupeb', 'transcript-postgraduate']);
        Staff::query()->create([
            'user_id' => $this->staffUser->id,
            'staff_number' => 'REG-TR-1',
            'office_department_id' => $office->id,
        ]);

        FeeItem::query()->create([
            'name' => 'Official transcript e-copy',
            'category' => 'transcript',
            'transcript_type' => 'e_copy',
            'program_id' => $this->program->id,
            'amount' => 5000,
            'wallet_allowed' => false,
            'is_required' => true,
            'is_active' => true,
            'display_order' => 1,
        ]);

        TranscriptRequestSettings::update([
            'transcript_requests_enabled' => true,
            'transcript_delivery_collect' => true,
            'transcript_delivery_generated_pdf' => true,
            'transcript_delivery_uploaded_pdf' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function requestPayload(array $overrides = []): array
    {
        return array_merge([
            'matric_number' => 'BUT/2018/0001',
            'email' => 'alumni@example.com',
            'program_id' => $this->program->id,
            'transcript_type' => 'e_copy',
            'delivery_email' => 'alumni@example.com',
            'channel' => 'undergraduate',
        ], $overrides);
    }

    public function test_meta_reports_types_without_a_single_fee(): void
    {
        $json = $this->getJson('/api/transcript-requests/meta')
            ->assertOk()
            ->json();

        $this->assertTrue($json['enabled']);
        $this->assertArrayNotHasKey('fee', $json);
        $this->assertSame('e_copy', $json['transcript_types'][0]['value']);
    }

    public function test_lookup_returns_programmes_for_matched_student(): void
    {
        $other = Program::query()->create([
            'department_id' => $this->program->department_id,
            'name' => 'B.Sc Soft Eng',
            'code' => 'BSC-SE',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $session = \App\Models\AcademicSession::query()->create([
            'label' => '2018/2019',
            'starts_on' => '2018-09-01',
            'ends_on' => '2019-07-31',
            'is_current' => false,
        ]);
        \App\Models\StudentLevelProgression::query()->create([
            'student_id' => $this->student->id,
            'academic_session_id' => $session->id,
            'program_id' => $other->id,
            'from_level' => 100,
            'to_level' => 200,
            'created_at' => now(),
        ]);

        $this->postJson('/api/transcript-requests/lookup', [
            'matric_number' => 'BUT/2018/0001',
            'email' => 'alumni@example.com',
            'channel' => 'undergraduate',
        ])
            ->assertOk()
            ->assertJsonPath('student.matric_number', 'BUT/2018/0001')
            ->assertJsonCount(2, 'programmes');
    }

    public function test_create_rejects_email_mismatch(): void
    {
        $this->postJson('/api/transcript-requests', $this->requestPayload([
            'email' => 'wrong@example.com',
        ]))->assertStatus(422);
    }

    public function test_create_blocked_without_fee(): void
    {
        FeeItem::query()->where('category', 'transcript')->update(['is_active' => false]);

        $this->postJson('/api/transcript-requests', $this->requestPayload())->assertStatus(422)
            ->assertJsonFragment(['message' => 'The transcript fee has not been set by Finance yet.']);
    }

    public function test_create_pay_demo_marks_paid_and_emails(): void
    {
        Mail::fake();

        $create = $this->postJson('/api/transcript-requests', $this->requestPayload([
            'copies' => 2,
            'purpose' => 'Further studies',
        ]))->assertCreated();

        $token = $create->json('request.token');
        $reference = $create->json('payment.reference');
        $this->assertNotEmpty($token);
        $this->assertTrue((bool) $create->json('payment.demo'));
        $this->assertEquals($this->program->id, $create->json('request.program.id'));

        $this->getJson('/api/transcript-requests/'.$token.'/verify/'.$reference)
            ->assertOk()
            ->assertJsonPath('request.status', 'paid');

        $this->assertDatabaseHas('transcript_requests', [
            'public_token' => $token,
            'status' => 'paid',
            'copies' => 2,
            'program_id' => $this->program->id,
            'transcript_type' => 'e_copy',
        ]);

        Mail::assertSent(TranscriptRequestPaidMail::class);
    }

    public function test_staff_can_mark_ready_collect_and_generated_pdf(): void
    {
        Mail::fake();
        Storage::fake(config('filesystems.default', 'local'));

        $create = $this->postJson('/api/transcript-requests', $this->requestPayload())->assertCreated();
        $token = $create->json('request.token');
        $reference = $create->json('payment.reference');
        $this->getJson('/api/transcript-requests/'.$token.'/verify/'.$reference)->assertOk();

        $request = TranscriptRequest::query()->where('public_token', $token)->firstOrFail();

        Sanctum::actingAs($this->staffUser);
        $this->postJson('/api/staff/transcript-requests/'.$request->id.'/start')
            ->assertOk()
            ->assertJsonPath('status', 'processing');

        $this->postJson('/api/staff/transcript-requests/'.$request->id.'/ready', [
            'delivery_mode' => 'collect',
        ])->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('delivery_mode', 'collect');

        Mail::assertSent(TranscriptRequestReadyMail::class);

        // Second request with generated PDF
        Mail::fake();
        $create2 = $this->postJson('/api/transcript-requests', $this->requestPayload())->assertCreated();
        $token2 = $create2->json('request.token');
        $this->getJson('/api/transcript-requests/'.$token2.'/verify/'.$create2->json('payment.reference'))->assertOk();
        $request2 = TranscriptRequest::query()->where('public_token', $token2)->firstOrFail();

        $this->postJson('/api/staff/transcript-requests/'.$request2->id.'/ready', [
            'delivery_mode' => 'generated_pdf',
        ])->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('downloadable', true);

        $this->get('/api/transcript-requests/'.$token2.'/download')->assertOk();
        Mail::assertSent(TranscriptRequestReadyMail::class);
    }

    public function test_staff_upload_and_reject(): void
    {
        Mail::fake();
        Storage::fake(config('filesystems.default', 'local'));

        $create = $this->postJson('/api/transcript-requests', $this->requestPayload())->assertCreated();
        $token = $create->json('request.token');
        $this->getJson('/api/transcript-requests/'.$token.'/verify/'.$create->json('payment.reference'))->assertOk();
        $request = TranscriptRequest::query()->where('public_token', $token)->firstOrFail();

        Sanctum::actingAs($this->staffUser);
        $file = UploadedFile::fake()->create('signed.pdf', 120, 'application/pdf');
        $this->post('/api/staff/transcript-requests/'.$request->id.'/ready', [
            'delivery_mode' => 'uploaded_pdf',
            'file' => $file,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('delivery_mode', 'uploaded_pdf')
            ->assertJsonPath('downloadable', true);

        Mail::assertSent(TranscriptRequestReadyMail::class);

        $create2 = $this->postJson('/api/transcript-requests', $this->requestPayload())->assertCreated();
        $token2 = $create2->json('request.token');
        $this->getJson('/api/transcript-requests/'.$token2.'/verify/'.$create2->json('payment.reference'))->assertOk();
        $request2 = TranscriptRequest::query()->where('public_token', $token2)->firstOrFail();

        Mail::fake();
        $this->postJson('/api/staff/transcript-requests/'.$request2->id.'/reject', [
            'reason' => 'Incomplete academic record',
        ])->assertOk()
            ->assertJsonPath('status', 'rejected');

        Mail::assertSent(TranscriptRequestRejectedMail::class);
        $this->get('/api/transcript-requests/'.$token2.'/download')->assertNotFound();
    }

    public function test_download_gated_until_ready(): void
    {
        $create = $this->postJson('/api/transcript-requests', $this->requestPayload())->assertCreated();
        $token = $create->json('request.token');

        $this->get('/api/transcript-requests/'.$token.'/download')->assertNotFound();
    }

    public function test_quote_returns_fee_for_programme_and_type(): void
    {
        FeeItem::query()->create([
            'name' => 'Within Nigeria transcript',
            'category' => 'transcript',
            'transcript_type' => 'within_nigeria',
            'program_id' => $this->program->id,
            'amount' => 15000,
            'wallet_allowed' => false,
            'is_required' => true,
            'is_active' => true,
            'display_order' => 2,
        ]);

        $this->postJson('/api/transcript-requests/quote', [
            'program_id' => $this->program->id,
            'transcript_type' => 'e_copy',
        ])->assertOk()
            ->assertJsonPath('fee.amount', 5000);

        $this->postJson('/api/transcript-requests/quote', [
            'program_id' => $this->program->id,
            'transcript_type' => 'within_nigeria',
        ])->assertOk()
            ->assertJsonPath('fee.amount', 15000);

        $this->postJson('/api/transcript-requests/quote', [
            'program_id' => $this->program->id,
            'transcript_type' => 'outside_nigeria',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Finance has not set a fee for this programme and transcript type.']);
    }

    public function test_create_requires_destination_fields_for_type(): void
    {
        $this->postJson('/api/transcript-requests', $this->requestPayload([
            'delivery_email' => null,
        ]))->assertStatus(422);

        $this->postJson('/api/transcript-requests', $this->requestPayload([
            'transcript_type' => 'within_nigeria',
            'delivery_email' => null,
        ]))->assertStatus(422);

        FeeItem::query()->create([
            'name' => 'Within Nigeria transcript',
            'category' => 'transcript',
            'transcript_type' => 'within_nigeria',
            'program_id' => $this->program->id,
            'amount' => 8000,
            'wallet_allowed' => false,
            'is_required' => true,
            'is_active' => true,
            'display_order' => 2,
        ]);
        FeeItem::query()->create([
            'name' => 'Student copy transcript',
            'category' => 'transcript',
            'transcript_type' => 'student_copy',
            'program_id' => $this->program->id,
            'amount' => 3000,
            'wallet_allowed' => false,
            'is_required' => true,
            'is_active' => true,
            'display_order' => 3,
        ]);

        $this->postJson('/api/transcript-requests', $this->requestPayload([
            'transcript_type' => 'within_nigeria',
            'delivery_email' => null,
            'delivery_address' => '12 Registry Road, Ota',
        ]))->assertCreated()
            ->assertJsonPath('request.transcript_type', 'within_nigeria');

        $this->postJson('/api/transcript-requests', $this->requestPayload([
            'transcript_type' => 'student_copy',
            'delivery_email' => null,
            'collection_method' => 'collect',
        ]))->assertCreated()
            ->assertJsonPath('request.collection_method', 'collect');
    }

    public function test_lookup_filters_programmes_by_channel(): void
    {
        Program::query()->create([
            'department_id' => $this->program->department_id,
            'name' => 'JUPEB Science',
            'code' => 'JUPEB-SCI',
            'award_type' => 'JUPEB',
            'study_level' => 'undergraduate',
            'entry_modes' => ['jupeb'],
            'duration_years' => 1,
            'is_active' => true,
        ]);

        $this->postJson('/api/transcript-requests/lookup', [
            'matric_number' => 'BUT/2018/0001',
            'email' => 'alumni@example.com',
            'channel' => 'jupeb',
        ])->assertStatus(422);
    }

    public function test_staff_queue_filters_by_channel(): void
    {
        $this->postJson('/api/transcript-requests', $this->requestPayload())->assertCreated();

        Sanctum::actingAs($this->staffUser);
        $this->getJson('/api/staff/transcript-requests?channel=undergraduate')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/staff/transcript-requests?channel=jupeb')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_staff_queue_filters_by_type_programme_date_and_collection(): void
    {
        $this->postJson('/api/transcript-requests', $this->requestPayload())->assertCreated();

        FeeItem::query()->create([
            'name' => 'Student copy transcript',
            'category' => 'transcript',
            'transcript_type' => 'student_copy',
            'program_id' => $this->program->id,
            'amount' => 3000,
            'wallet_allowed' => false,
            'is_required' => true,
            'is_active' => true,
            'display_order' => 3,
        ]);
        $this->postJson('/api/transcript-requests', $this->requestPayload([
            'transcript_type' => 'student_copy',
            'delivery_email' => null,
            'collection_method' => 'collect',
        ]))->assertCreated();

        Sanctum::actingAs($this->staffUser);
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $this->getJson('/api/staff/transcript-requests?channel=undergraduate&transcript_type=e_copy')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/staff/transcript-requests?channel=undergraduate&collection_method=collect')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/staff/transcript-requests?channel=undergraduate&program_id='.$this->program->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->getJson('/api/staff/transcript-requests?channel=undergraduate&from='.$today.'&to='.$today)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->getJson('/api/staff/transcript-requests?channel=undergraduate&to='.$yesterday)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/staff/transcript-requests?channel=undergraduate&search=BSC-CS')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }
}
