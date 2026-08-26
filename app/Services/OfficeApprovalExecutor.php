<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\Campus;
use App\Models\ClinicVisit;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Document;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\FeeCategory;
use App\Models\FeeItem;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\Intake;
use App\Models\Invoice;
use App\Models\InvoiceRebate;
use App\Models\OlevelSubject;
use App\Models\Program;
use App\Models\ProgrammeFee;
use App\Models\RebateType;
use App\Models\RegistrationExtension;
use App\Models\Student;
use App\Models\User;
use App\Models\AcademicLevel;
use App\Models\AcademicSession;
use App\Models\UnitLimit;
use App\Support\OfficeApprovalCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OfficeApprovalExecutor
{
    public function run(string $actionKey, array $payload): mixed
    {
        return match ($actionKey) {
            'test.echo', 'test.echo_create', 'test.echo_delete' => $payload,
            'hostel.allocate' => $this->hostelAllocate($payload),
            'hostel.auto_allocate' => $this->hostelAutoAllocate($payload),
            'hostel.approve' => $this->hostelApprove($payload),
            'hostel.reject' => $this->hostelReject($payload),
            'hostel.vacate' => $this->hostelVacate($payload),
            default => $this->replayViaRequest($actionKey, $payload),
        };
    }

    private function hostelAllocate(array $payload): mixed
    {
        $controller = app(\App\Http\Controllers\HostelController::class);
        $request = Request::create('/api/hostel-allocations', 'POST', [
            'student_id' => $payload['student_id'],
            'hostel_bed_id' => $payload['hostel_bed_id'],
            'academic_term_id' => $payload['academic_term_id'] ?? null,
        ]);
        $this->actingAs($payload, $request);

        return $controller->allocate($request);
    }

    private function hostelAutoAllocate(array $payload): mixed
    {
        $controller = app(\App\Http\Controllers\HostelController::class);
        $request = Request::create('/api/hostel-allocations/auto', 'POST', $payload);
        $this->actingAs($payload, $request);

        return $controller->autoAllocate($request);
    }

    private function hostelApprove(array $payload): mixed
    {
        $allocation = HostelAllocation::query()->findOrFail($payload['allocation_id']);

        return app(\App\Http\Controllers\HostelController::class)->approve($allocation);
    }

    private function hostelReject(array $payload): mixed
    {
        $allocation = HostelAllocation::query()->findOrFail($payload['allocation_id']);

        return app(\App\Http\Controllers\HostelController::class)->reject($allocation);
    }

    private function hostelVacate(array $payload): mixed
    {
        $allocation = HostelAllocation::query()->findOrFail($payload['allocation_id']);

        return app(\App\Http\Controllers\HostelController::class)->vacate($allocation);
    }

    private function replayViaRequest(string $actionKey, array $payload): mixed
    {
        $map = $this->controllerMap();
        $entry = $map[$actionKey] ?? null;
        abort_unless($entry, 500, 'No executor is registered for '.$actionKey);

        [$class, $method, $param] = array_pad($entry, 3, null);
        $controller = app($class);
        $request = Request::create('/', $payload['_http_method'] ?? 'POST', $payload['data'] ?? $payload);
        $this->actingAs($payload, $request);

        $args = [];
        $ref = new \ReflectionMethod($controller, $method);
        foreach ($ref->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($typeName === Request::class || ($typeName && is_subclass_of($typeName, Request::class))) {
                $args[] = $request;
                continue;
            }
            if ($typeName && class_exists($typeName) && is_subclass_of($typeName, \Illuminate\Database\Eloquent\Model::class)) {
                $key = \Illuminate\Support\Str::snake(class_basename($typeName)).'_id';
                if ($param && ($param['class'] ?? null) === $typeName) {
                    $key = $param['key'];
                }
                $id = $payload[$key] ?? $payload[str_replace('_id', '', $key).'_id'] ?? null;
                abort_unless($id, 500, 'Missing '.$key.' to replay '.$actionKey);
                $args[] = $typeName::query()->findOrFail($id);
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
            }
        }

        return $controller->{$method}(...$args);
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: ?array{key: string, class: class-string}}>
     */
    private function controllerMap(): array
    {
        return [
            'hostel.store' => [\App\Http\Controllers\HostelController::class, 'store', null],
            'hostel.update' => [\App\Http\Controllers\HostelController::class, 'update', ['key' => 'hostel_id', 'class' => Hostel::class]],
            'hostel.destroy' => [\App\Http\Controllers\HostelController::class, 'destroy', ['key' => 'hostel_id', 'class' => Hostel::class]],
            'hostel.sync_level_windows' => [\App\Http\Controllers\HostelController::class, 'syncLevelWindows', null],
            'hostel.store_block' => [\App\Http\Controllers\HostelController::class, 'storeBlock', ['key' => 'hostel_id', 'class' => Hostel::class]],
            'hostel.update_block' => [\App\Http\Controllers\HostelController::class, 'updateBlock', ['key' => 'hostel_block_id', 'class' => HostelBlock::class]],
            'hostel.destroy_block' => [\App\Http\Controllers\HostelController::class, 'destroyBlock', ['key' => 'hostel_block_id', 'class' => HostelBlock::class]],
            'hostel.store_room' => [\App\Http\Controllers\HostelController::class, 'storeRoom', ['key' => 'hostel_block_id', 'class' => HostelBlock::class]],
            'hostel.update_room' => [\App\Http\Controllers\HostelController::class, 'updateRoom', ['key' => 'hostel_room_id', 'class' => HostelRoom::class]],
            'hostel.destroy_room' => [\App\Http\Controllers\HostelController::class, 'destroyRoom', ['key' => 'hostel_room_id', 'class' => HostelRoom::class]],
            'hostel.reserve_room' => [\App\Http\Controllers\HostelController::class, 'reserveRoom', ['key' => 'hostel_room_id', 'class' => HostelRoom::class]],
            'hostel.release_room' => [\App\Http\Controllers\HostelController::class, 'releaseRoom', ['key' => 'hostel_room_id', 'class' => HostelRoom::class]],
            'hostel.disable_room' => [\App\Http\Controllers\HostelController::class, 'disableRoom', ['key' => 'hostel_room_id', 'class' => HostelRoom::class]],
            'hostel.enable_room' => [\App\Http\Controllers\HostelController::class, 'enableRoom', ['key' => 'hostel_room_id', 'class' => HostelRoom::class]],
            'admissions.transition' => [\App\Http\Controllers\ApplicationController::class, 'transition', ['key' => 'application_id', 'class' => Application::class]],
            'admissions.revert' => [\App\Http\Controllers\ApplicationController::class, 'revert', ['key' => 'application_id', 'class' => Application::class]],
            'admissions.staff_update' => [\App\Http\Controllers\ApplicationController::class, 'staffUpdate', ['key' => 'application_id', 'class' => Application::class]],
            'admissions.update_acceptance_fee' => [\App\Http\Controllers\ApplicationController::class, 'updateAcceptanceFee', ['key' => 'application_id', 'class' => Application::class]],
            'medical.approve_appointment' => [\App\Http\Controllers\ClinicController::class, 'approveAppointment', ['key' => 'visit_id', 'class' => ClinicVisit::class]],
            'medical.reject_appointment' => [\App\Http\Controllers\ClinicController::class, 'rejectAppointment', ['key' => 'visit_id', 'class' => ClinicVisit::class]],
            'medical.finalize_bill' => [\App\Http\Controllers\ClinicController::class, 'finalizeBill', ['key' => 'visit_id', 'class' => ClinicVisit::class]],
            'medical.update_profile' => [\App\Http\Controllers\MedicalController::class, 'updateProfile', ['key' => 'student_id', 'class' => Student::class]],
            'finance.store_fee' => [\App\Http\Controllers\FinanceController::class, 'storeFee', null],
            'finance.update_fee' => [\App\Http\Controllers\FinanceController::class, 'updateFee', ['key' => 'fee_id', 'class' => FeeItem::class]],
            'finance.destroy_fee' => [\App\Http\Controllers\FinanceController::class, 'destroyFee', ['key' => 'fee_id', 'class' => FeeItem::class]],
            'finance.store_fee_category' => [\App\Http\Controllers\FinanceController::class, 'storeFeeCategory', null],
            'finance.update_fee_category' => [\App\Http\Controllers\FinanceController::class, 'updateFeeCategory', ['key' => 'fee_category_id', 'class' => FeeCategory::class]],
            'finance.destroy_fee_category' => [\App\Http\Controllers\FinanceController::class, 'destroyFeeCategory', ['key' => 'fee_category_id', 'class' => FeeCategory::class]],
            'finance.generate_invoice' => [\App\Http\Controllers\FinanceController::class, 'generate', null],
            'finance.disable_invoice' => [\App\Http\Controllers\FinanceController::class, 'disableInvoice', ['key' => 'invoice_id', 'class' => Invoice::class]],
            'finance.enable_invoice' => [\App\Http\Controllers\FinanceController::class, 'enableInvoice', ['key' => 'invoice_id', 'class' => Invoice::class]],
            'finance.store_rebate_type' => [\App\Http\Controllers\RebateController::class, 'storeType', null],
            'finance.update_rebate_type' => [\App\Http\Controllers\RebateController::class, 'updateType', ['key' => 'rebate_type_id', 'class' => RebateType::class]],
            'finance.destroy_rebate_type' => [\App\Http\Controllers\RebateController::class, 'destroyType', ['key' => 'rebate_type_id', 'class' => RebateType::class]],
            'finance.apply_rebate' => [\App\Http\Controllers\RebateController::class, 'apply', ['key' => 'invoice_id', 'class' => Invoice::class]],
            'finance.reverse_rebate' => [\App\Http\Controllers\RebateController::class, 'reverse', ['key' => 'invoice_id', 'class' => Invoice::class]],
            'finance.store_programme_fee' => [\App\Http\Controllers\ProgrammeFeeController::class, 'store', null],
            'finance.update_programme_fee' => [\App\Http\Controllers\ProgrammeFeeController::class, 'update', ['key' => 'programme_fee_id', 'class' => ProgrammeFee::class]],
            'finance.destroy_programme_fee' => [\App\Http\Controllers\ProgrammeFeeController::class, 'destroy', ['key' => 'programme_fee_id', 'class' => ProgrammeFee::class]],
            'finance.bulk_programme_fees' => [\App\Http\Controllers\ProgrammeFeeController::class, 'bulkStore', null],
            'finance.copy_programme_fees' => [\App\Http\Controllers\ProgrammeFeeController::class, 'copySchedule', null],
            'documents.issue' => [\App\Http\Controllers\DocumentController::class, 'issue', null],
            'academic.staff_register' => [\App\Http\Controllers\CourseRegistrationController::class, 'staffRegister', null],
            'academic.staff_drop' => [\App\Http\Controllers\CourseRegistrationController::class, 'staffDrop', ['key' => 'enrollment_id', 'class' => Enrollment::class]],
            'academic.grant_grace' => [\App\Http\Controllers\CourseRegistrationController::class, 'grantGrace', null],
            'academic.review_extension' => [\App\Http\Controllers\RegistrationExtensionController::class, 'review', ['key' => 'extension_id', 'class' => RegistrationExtension::class]],
            'academic.store_unit_limit' => [\App\Http\Controllers\UnitLimitController::class, 'store', null],
            'academic.update_unit_limit' => [\App\Http\Controllers\UnitLimitController::class, 'update', ['key' => 'unit_limit_id', 'class' => UnitLimit::class]],
            'academic.destroy_unit_limit' => [\App\Http\Controllers\UnitLimitController::class, 'destroy', ['key' => 'unit_limit_id', 'class' => UnitLimit::class]],
            'academic.store_offering' => [\App\Http\Controllers\CourseOfferingController::class, 'store', null],
            'academic.update_offering' => [\App\Http\Controllers\CourseOfferingController::class, 'update', ['key' => 'offering_id', 'class' => CourseOffering::class]],
            'academic.destroy_offering' => [\App\Http\Controllers\CourseOfferingController::class, 'destroy', ['key' => 'offering_id', 'class' => CourseOffering::class]],
            'academic.store_program' => [\App\Http\Controllers\AcademicController::class, 'storeProgram', null],
            'academic.update_program' => [\App\Http\Controllers\AcademicController::class, 'updateProgram', ['key' => 'program_id', 'class' => Program::class]],
            'academic.destroy_program' => [\App\Http\Controllers\AcademicController::class, 'destroyProgram', ['key' => 'program_id', 'class' => Program::class]],
            'academic.store_course' => [\App\Http\Controllers\AcademicController::class, 'storeCourse', null],
            'academic.update_course' => [\App\Http\Controllers\AcademicController::class, 'updateCourse', ['key' => 'course_id', 'class' => Course::class]],
            'academic.destroy_course' => [\App\Http\Controllers\AcademicController::class, 'destroyCourse', ['key' => 'course_id', 'class' => Course::class]],
            'academic.store_campus' => [\App\Http\Controllers\InstitutionController::class, 'storeCampus', null],
            'academic.update_campus' => [\App\Http\Controllers\InstitutionController::class, 'updateCampus', ['key' => 'campus_id', 'class' => Campus::class]],
            'academic.destroy_campus' => [\App\Http\Controllers\InstitutionController::class, 'destroyCampus', ['key' => 'campus_id', 'class' => Campus::class]],
            'academic.store_faculty' => [\App\Http\Controllers\InstitutionController::class, 'storeFaculty', null],
            'academic.update_faculty' => [\App\Http\Controllers\InstitutionController::class, 'updateFaculty', ['key' => 'faculty_id', 'class' => Faculty::class]],
            'academic.destroy_faculty' => [\App\Http\Controllers\InstitutionController::class, 'destroyFaculty', ['key' => 'faculty_id', 'class' => Faculty::class]],
            'academic.store_department' => [\App\Http\Controllers\InstitutionController::class, 'storeDepartment', ['key' => 'skip', 'class' => Department::class]],
            'academic.update_department' => [\App\Http\Controllers\InstitutionController::class, 'updateDepartment', ['key' => 'department_id', 'class' => Department::class]],
            'academic.destroy_department' => [\App\Http\Controllers\InstitutionController::class, 'destroyDepartment', ['key' => 'department_id', 'class' => Department::class]],
            'academic.store_session' => [\App\Http\Controllers\InstitutionController::class, 'storeSession', null],
            'academic.update_session' => [\App\Http\Controllers\InstitutionController::class, 'updateSession', ['key' => 'session_id', 'class' => AcademicSession::class]],
            'academic.destroy_session' => [\App\Http\Controllers\InstitutionController::class, 'destroySession', ['key' => 'session_id', 'class' => AcademicSession::class]],
            'academic.close_session' => [\App\Http\Controllers\InstitutionController::class, 'closeSession', ['key' => 'session_id', 'class' => AcademicSession::class]],
            'academic.graduate' => [\App\Http\Controllers\GraduationController::class, 'confer', null],
            'results.board_clear' => [\App\Http\Controllers\ResultsController::class, 'boardClear', null],
            'results.release' => [\App\Http\Controllers\ResultsController::class, 'release', null],
            'academic.store_term' => [\App\Http\Controllers\InstitutionController::class, 'storeTerm', null],
            'academic.update_term' => [\App\Http\Controllers\InstitutionController::class, 'updateTerm', ['key' => 'term_id', 'class' => AcademicTerm::class]],
            'academic.destroy_term' => [\App\Http\Controllers\InstitutionController::class, 'destroyTerm', ['key' => 'term_id', 'class' => AcademicTerm::class]],
            'academic.store_level' => [\App\Http\Controllers\AcademicSetupController::class, 'storeLevel', null],
            'academic.update_level' => [\App\Http\Controllers\AcademicSetupController::class, 'updateLevel', ['key' => 'academic_level_id', 'class' => AcademicLevel::class]],
            'academic.destroy_level' => [\App\Http\Controllers\AcademicSetupController::class, 'destroyLevel', ['key' => 'academic_level_id', 'class' => AcademicLevel::class]],
            'academic.store_intake' => [\App\Http\Controllers\AcademicSetupController::class, 'storeIntake', null],
            'academic.update_intake' => [\App\Http\Controllers\AcademicSetupController::class, 'updateIntake', ['key' => 'intake_id', 'class' => Intake::class]],
            'academic.destroy_intake' => [\App\Http\Controllers\AcademicSetupController::class, 'destroyIntake', ['key' => 'intake_id', 'class' => Intake::class]],
            'academic.store_olevel' => [\App\Http\Controllers\AcademicSetupController::class, 'storeOlevelSubject', null],
            'academic.update_olevel' => [\App\Http\Controllers\AcademicSetupController::class, 'updateOlevelSubject', ['key' => 'olevel_subject_id', 'class' => OlevelSubject::class]],
            'academic.destroy_olevel' => [\App\Http\Controllers\AcademicSetupController::class, 'destroyOlevelSubject', ['key' => 'olevel_subject_id', 'class' => OlevelSubject::class]],
            'students.update' => [\App\Http\Controllers\StudentController::class, 'update', ['key' => 'student_id', 'class' => Student::class]],
            'announcements.store' => [\App\Http\Controllers\AnnouncementController::class, 'store', null],
            'announcements.update' => [\App\Http\Controllers\AnnouncementController::class, 'update', ['key' => 'announcement_id', 'class' => Announcement::class]],
            'announcements.publish' => [\App\Http\Controllers\AnnouncementController::class, 'publish', ['key' => 'announcement_id', 'class' => Announcement::class]],
            'announcements.unpublish' => [\App\Http\Controllers\AnnouncementController::class, 'unpublish', ['key' => 'announcement_id', 'class' => Announcement::class]],
            'announcements.destroy' => [\App\Http\Controllers\AnnouncementController::class, 'destroy', ['key' => 'announcement_id', 'class' => Announcement::class]],
        ];
    }

    private function actingAs(array $payload, Request $request): void
    {
        $userId = $payload['_actor_user_id'] ?? null;
        if ($userId) {
            $user = User::query()->find($userId);
            if ($user) {
                $request->setUserResolver(fn () => $user);
                auth()->setUser($user);
            }
        }
        app()->instance('request', $request);
    }
}
