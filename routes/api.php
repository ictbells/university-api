<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AcademicSetupController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ApplicantImportController;
use App\Http\Controllers\InvoiceImportController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CandidateDataController;
use App\Http\Controllers\ClinicController;
use App\Http\Controllers\CourseOfferingController;
use App\Http\Controllers\CourseRegistrationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExamClearanceController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GraduationController;
use App\Http\Controllers\HostelController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MedicalController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfficeApprovalController;
use App\Http\Controllers\OfficeStructureController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProgrammeFeeController;
use App\Http\Controllers\RebateController;
use App\Http\Controllers\RefereePortalController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RegistrationExtensionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SecuritySettingsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentImportController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\TranscriptRequestController;
use App\Http\Controllers\UnitLimitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WalletImportController;
use App\Support\CandidateEligibility;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->middleware('web')
    ->name('sanctum.csrf-cookie.api');

Route::post('/nin/preview', [AuthController::class, 'previewNin']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgot']);
Route::post('/reset-password', [AuthController::class, 'reset']);
Route::post('/two-factor/setup', [TwoFactorController::class, 'setup']);
Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm']);
Route::post('/two-factor/verify', [TwoFactorController::class, 'verify']);
Route::post('/payments/paystack/webhook', [PaymentController::class, 'webhook']);

Route::get('/transcript-requests/meta', [TranscriptRequestController::class, 'meta']);
Route::post('/transcript-requests/lookup', [TranscriptRequestController::class, 'lookup'])
    ->middleware('throttle:20,1');
Route::post('/transcript-requests/quote', [TranscriptRequestController::class, 'quote'])
    ->middleware('throttle:30,1');
Route::post('/transcript-requests', [TranscriptRequestController::class, 'store'])
    ->middleware('throttle:10,1');
Route::get('/transcript-requests/{token}', [TranscriptRequestController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+');
Route::post('/transcript-requests/{token}/pay', [TranscriptRequestController::class, 'pay'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:10,1');
Route::get('/transcript-requests/{token}/verify/{reference}', [TranscriptRequestController::class, 'verify'])
    ->where('token', '[A-Za-z0-9]+');
Route::get('/transcript-requests/{token}/download', [TranscriptRequestController::class, 'download'])
    ->where('token', '[A-Za-z0-9]+');

Route::get('/portal-info', [InstitutionController::class, 'portalInfo']);
Route::get('/intakes', [AcademicSetupController::class, 'openIntakes']);
Route::get('/programs/{program}/supervisors', [AcademicController::class, 'supervisors']);
Route::get('/workflow-templates', [AcademicController::class, 'workflowTemplates']);
Route::get('/referee/{token}', [RefereePortalController::class, 'show']);
Route::post('/referee/{token}', [RefereePortalController::class, 'store']);
Route::get('/academic-levels', [AcademicSetupController::class, 'levels']);
Route::get('/olevel-subjects', [AcademicSetupController::class, 'olevelSubjects']);
Route::get('/states', [LocationController::class, 'states']);
Route::get('/lgas', [LocationController::class, 'lgas']);
Route::get('/candidate-list/required', function () {
    return response()->json(['required' => CandidateEligibility::enforcementEnabled()]);
});
Route::get('/candidate-data/{jambRegistration}', [CandidateDataController::class, 'lookup'])
    ->where('jambRegistration', '^(?!sessions$|import-template$).+');

Route::middleware(['auth:sanctum', 'staff.security'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/me/passport', [AuthController::class, 'myPassport']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/office-approvals', [OfficeApprovalController::class, 'index']);
    Route::get('/office-approvals/{officeApproval}', [OfficeApprovalController::class, 'show']);
    Route::post('/office-approvals/{officeApproval}/approve', [OfficeApprovalController::class, 'approve']);
    Route::post('/office-approvals/{officeApproval}/reject', [OfficeApprovalController::class, 'reject']);

    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);

    Route::get('/candidate-data/sessions', [CandidateDataController::class, 'sessions'])
        ->middleware('permission:admissions.import');
    Route::get('/candidate-data/import-template', [CandidateDataController::class, 'template'])
        ->middleware('permission:admissions.import');
    Route::get('/candidate-data', [CandidateDataController::class, 'index'])
        ->middleware('permission:admissions.import');
    Route::post('/candidate-data/upload', [CandidateDataController::class, 'upload'])
        ->middleware('permission:admissions.import');

    Route::get('/applicants/import-options', [ApplicantImportController::class, 'options'])
        ->middleware('permission:admissions.import');
    Route::get('/applicants/import-template', [ApplicantImportController::class, 'template'])
        ->middleware('permission:admissions.import');
    Route::post('/applicants/import', [ApplicantImportController::class, 'import'])
        ->middleware('permission:admissions.import');
    Route::get('/applicants/import/{importId}', [ApplicantImportController::class, 'status'])
        ->middleware('permission:admissions.import');
    Route::get('/applicants/import/{importId}/errors', [ApplicantImportController::class, 'errors'])
        ->middleware('permission:admissions.import');

    Route::get('/students/import-options', [StudentImportController::class, 'options']);
    Route::get('/students/import-template', [StudentImportController::class, 'template']);
    Route::post('/students/import', [StudentImportController::class, 'import']);
    Route::get('/students/import/{importId}', [StudentImportController::class, 'status']);
    Route::get('/students/import/{importId}/errors', [StudentImportController::class, 'errors']);

    Route::get('/colleges', [AcademicSetupController::class, 'applicantColleges']);
    Route::get('/programs', [AcademicController::class, 'programs']);
    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::get('/applications/export', [ApplicationController::class, 'export'])
        ->middleware('permission:admissions.view');
    Route::get('/applications/sessions', [ApplicationController::class, 'sessions'])
        ->middleware('permission:admissions.view');
    Route::post('/applications', [ApplicationController::class, 'start']);
    Route::get('/applications/{application}', [ApplicationController::class, 'show']);
    Route::patch('/applications/{application}', [ApplicationController::class, 'staffUpdate']);
    Route::get('/applications/{application}/form-print', [ApplicationController::class, 'formPrint']);
    Route::get('/applications/{application}/offer-letter', [ApplicationController::class, 'offerLetter']);
    Route::post('/applications/{application}/steps', [ApplicationController::class, 'saveStep']);
    Route::post('/applications/{application}/submit', [ApplicationController::class, 'submit']);
    Route::get('/applications/{application}/eligibility', [ApplicationController::class, 'eligibility']);
    Route::post('/applications/{application}/referees/{invite}/resend', [ApplicationController::class, 'resendReferee']);
    Route::post('/applications/{application}/documents', [ApplicationController::class, 'uploadDocument']);
    Route::get('/applications/{application}/documents/{document}/file', [ApplicationController::class, 'streamDocument']);
    Route::get('/applications/{application}/passport', [ApplicationController::class, 'streamPassport']);
    Route::post('/applications/{application}/nin', [ApplicationController::class, 'verifyNin']);
    Route::post('/applications/{application}/transition', [ApplicationController::class, 'transition']);
    Route::post('/applications/{application}/revert', [ApplicationController::class, 'revert']);
    Route::patch('/applications/{application}/acceptance-fee', [ApplicationController::class, 'updateAcceptanceFee']);

    Route::get('/registrations', [RegistrationController::class, 'index'])
        ->middleware('permission:registrations.view');
    Route::get('/registrations/export', [RegistrationController::class, 'export'])
        ->middleware('permission:registrations.view');
    Route::get('/registrations/sessions', [RegistrationController::class, 'sessions'])
        ->middleware('permission:registrations.view');

    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{student}', [StudentController::class, 'show']);
    Route::patch('/students/{student}', [StudentController::class, 'update']);
    Route::post('/students/{student}/confer', [GraduationController::class, 'conferOne']);

    Route::get('/wallet', [WalletController::class, 'show']);
    Route::post('/wallet/pay/{invoice}', [WalletController::class, 'payInvoice']);
    Route::post('/wallet/topup', [WalletController::class, 'topup']);

    Route::get('/invoices', [FinanceController::class, 'invoices']);
    Route::get('/invoices/export', [FinanceController::class, 'exportInvoices'])
        ->middleware('permission:finance.invoices.manage');
    Route::get('/invoices/import-template', [InvoiceImportController::class, 'template']);
    Route::get('/invoices/import-pending', [InvoiceImportController::class, 'pending']);
    Route::post('/invoices/import', [InvoiceImportController::class, 'import']);
    Route::get('/invoices/import/{importId}', [InvoiceImportController::class, 'status']);
    Route::get('/invoices/import/{importId}/errors', [InvoiceImportController::class, 'errors']);
    Route::get('/wallet/import-template', [WalletImportController::class, 'template']);
    Route::get('/wallet/import-pending', [WalletImportController::class, 'pending']);
    Route::post('/wallet/import', [WalletImportController::class, 'import']);
    Route::get('/wallet/import/{importId}', [WalletImportController::class, 'status']);
    Route::get('/wallet/import/{importId}/errors', [WalletImportController::class, 'errors']);
    Route::get('/invoices/{invoice}/receipt', [FinanceController::class, 'receipt']);
    Route::get('/transactions', [FinanceController::class, 'history']);
    Route::get('/my-programme-fees', [FinanceController::class, 'myProgrammeFeeSchedule']);
    Route::get('/fees', [FinanceController::class, 'fees']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments/paystack/initialize', [PaymentController::class, 'initialize']);
    Route::get('/payments/paystack/verify/{reference}', [PaymentController::class, 'verify']);
    Route::get('/payments/{payment}/receipt', [FinanceController::class, 'paymentReceipt']);

    Route::get('/academic/courses', [AcademicController::class, 'courses'])
        ->middleware('academic.resource:courses,programmes,offerings,course-registration,programme-courses');
    Route::get('/academic/my-enrollments', [AcademicController::class, 'myEnrollments']);
    Route::get('/academic/my-registration', [CourseRegistrationController::class, 'myContext']);
    Route::get('/academic/my-registration-context', [CourseRegistrationController::class, 'myContext']);
    Route::post('/academic/my-registration', [CourseRegistrationController::class, 'myRegister']);
    Route::post('/academic/my-enrollments', [CourseRegistrationController::class, 'myRegister']);
    Route::post('/academic/my-registration/enrollments/{enrollment}/drop', [CourseRegistrationController::class, 'myDrop']);
    Route::delete('/academic/my-enrollments/{enrollment}', [CourseRegistrationController::class, 'myDrop']);
    Route::get('/academic/my-registration-extension', [CourseRegistrationController::class, 'myExtension']);
    Route::post('/academic/my-registration/extension', [CourseRegistrationController::class, 'requestExtension']);
    Route::post('/academic/my-registration-extension', [CourseRegistrationController::class, 'requestExtension']);
    Route::get('/academic/transcript/{student?}', [AcademicController::class, 'transcript']);
    Route::get('/exam-clearance', [ExamClearanceController::class, 'mine']);
    Route::get('/exam-clearance/students', [ExamClearanceController::class, 'index']);
    Route::get('/exam-clearance/students/{student}', [ExamClearanceController::class, 'show']);

    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/hostels', [HostelController::class, 'index']);
    Route::get('/hostels/overview', [HostelController::class, 'overview']);
    Route::get('/institution', [InstitutionController::class, 'show']);

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::put('/users/{user}/roles', [UserController::class, 'assignRoles']);
    });

    Route::middleware('permission:roles.manage')->group(function () {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::patch('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
        Route::put('/roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
    });
    Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.manage');

    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('/security-settings', [SecuritySettingsController::class, 'show']);
        Route::put('/security-settings', [SecuritySettingsController::class, 'update']);
    });

    Route::middleware('permission:resources.view')->group(function () {
        Route::get('/resources', [ResourceController::class, 'index']);
        Route::get('/resources/{slug}', [ResourceController::class, 'show']);
        Route::get('/resources/{slug}/download', [ResourceController::class, 'download']);
        Route::get('/resources/{slug}/pdf', [ResourceController::class, 'downloadPdf']);
    });

    Route::middleware('permission:institution.manage')->group(function () {
        Route::get('/office-structure', [OfficeStructureController::class, 'index']);
        Route::get('/office-staff-options', [OfficeStructureController::class, 'staffOptions']);
        Route::get('/staff-nav/catalog', [OfficeStructureController::class, 'navCatalog']);
        Route::post('/office-departments', [OfficeStructureController::class, 'storeDepartment']);
        Route::post('/office-units', [OfficeStructureController::class, 'storeUnit']);
        Route::post('/office-subunits', [OfficeStructureController::class, 'storeSubunit']);
        Route::patch('/office-departments/{officeDepartment}', [OfficeStructureController::class, 'updateDepartment']);
        Route::patch('/office-units/{officeUnit}', [OfficeStructureController::class, 'updateUnit']);
        Route::patch('/office-subunits/{officeSubunit}', [OfficeStructureController::class, 'updateSubunit']);
        Route::put('/office-departments/{officeDepartment}/nav-links', [OfficeStructureController::class, 'syncDepartmentNavLinks']);
        Route::put('/office-units/{officeUnit}/nav-links', [OfficeStructureController::class, 'syncUnitNavLinks']);
        Route::put('/office-subunits/{officeSubunit}/nav-links', [OfficeStructureController::class, 'syncSubunitNavLinks']);
        Route::delete('/office-departments/{officeDepartment}', [OfficeStructureController::class, 'destroyDepartment']);
        Route::delete('/office-units/{officeUnit}', [OfficeStructureController::class, 'destroyUnit']);
        Route::delete('/office-subunits/{officeSubunit}', [OfficeStructureController::class, 'destroySubunit']);
        Route::put('/institution/settings', [InstitutionController::class, 'updateSettings']);
    });

    $academicSetupKeys = 'campuses,colleges,departments,sessions,levels,courses,programmes,intakes,olevel';

    Route::get('/academic/setup', [AcademicSetupController::class, 'index'])
        ->middleware('academic.resource:'.$academicSetupKeys);
    Route::get('/academic/campuses', [AcademicSetupController::class, 'campuses'])
        ->middleware('academic.resource:campuses,colleges,graduation');
    Route::middleware('academic.resource:campuses')->group(function () {
        Route::post('/campuses', [InstitutionController::class, 'storeCampus']);
        Route::patch('/campuses/{campus}', [InstitutionController::class, 'updateCampus']);
        Route::delete('/campuses/{campus}', [InstitutionController::class, 'destroyCampus']);
    });
    Route::get('/academic/faculties', [AcademicSetupController::class, 'faculties'])
        ->middleware('academic.resource:colleges,departments,programmes,courses,programme-courses');
    Route::middleware('academic.resource:colleges')->group(function () {
        Route::get('/academic/faculties/import-template', [AcademicSetupController::class, 'importFacultiesTemplate']);
        Route::post('/academic/faculties/import', [AcademicSetupController::class, 'importFaculties']);
        Route::post('/faculties', [InstitutionController::class, 'storeFaculty']);
        Route::patch('/faculties/{faculty}', [InstitutionController::class, 'updateFaculty']);
        Route::delete('/faculties/{faculty}', [InstitutionController::class, 'destroyFaculty']);
    });
    Route::get('/academic/departments', [AcademicSetupController::class, 'departments'])
        ->middleware('academic.resource:departments,programmes,courses,programme-courses');
    Route::middleware('academic.resource:departments')->group(function () {
        Route::get('/academic/departments/import-template', [AcademicSetupController::class, 'importDepartmentsTemplate']);
        Route::post('/academic/departments/import', [AcademicSetupController::class, 'importDepartments']);
        Route::post('/departments', [InstitutionController::class, 'storeDepartment']);
        Route::patch('/departments/{department}', [InstitutionController::class, 'updateDepartment']);
        Route::delete('/departments/{department}', [InstitutionController::class, 'destroyDepartment']);
    });
    Route::get('/academic/terms', [AcademicSetupController::class, 'terms'])
        ->middleware('academic.resource:sessions,intakes,offerings,course-registration,unit-limits,registration-extensions');
    Route::get('/academic/sessions', [AcademicSetupController::class, 'sessions'])
        ->middleware('academic.resource:sessions,intakes,offerings,course-registration,unit-limits,registration-extensions,graduation');
    Route::middleware('academic.resource:graduation')->group(function () {
        Route::get('/academic/graduation/candidates', [GraduationController::class, 'candidates']);
        Route::post('/academic/graduation/confer', [GraduationController::class, 'confer']);
    });
    Route::middleware('academic.resource:sessions')->group(function () {
        Route::post('/academic/sessions', [InstitutionController::class, 'storeSession']);
        Route::patch('/academic/sessions/{session}', [InstitutionController::class, 'updateSession']);
        Route::delete('/academic/sessions/{session}', [InstitutionController::class, 'destroySession']);
        Route::get('/academic/sessions/{session}/close-preview', [InstitutionController::class, 'closeSessionPreview']);
        Route::post('/academic/sessions/{session}/close', [InstitutionController::class, 'closeSession']);
        Route::post('/terms', [InstitutionController::class, 'storeTerm']);
        Route::patch('/terms/{term}', [InstitutionController::class, 'updateTerm']);
        Route::delete('/terms/{term}', [InstitutionController::class, 'destroyTerm']);
    });
    Route::get('/academic/levels', [AcademicSetupController::class, 'levelsList'])
        ->middleware('academic.resource:levels,unit-limits,course-registration,programme-courses,programmes');
    Route::middleware('academic.resource:levels')->group(function () {
        Route::post('/academic/levels', [AcademicSetupController::class, 'storeLevel']);
        Route::patch('/academic/levels/{academicLevel}', [AcademicSetupController::class, 'updateLevel']);
        Route::delete('/academic/levels/{academicLevel}', [AcademicSetupController::class, 'destroyLevel']);
    });
    Route::get('/academic/intakes', [AcademicSetupController::class, 'intakesList'])
        ->middleware('academic.resource:intakes');
    Route::middleware('academic.resource:intakes')->group(function () {
        Route::post('/intakes', [AcademicSetupController::class, 'storeIntake']);
        Route::patch('/intakes/{intake}', [AcademicSetupController::class, 'updateIntake']);
        Route::delete('/intakes/{intake}', [AcademicSetupController::class, 'destroyIntake']);
    });
    Route::get('/academic/olevel-subjects', [AcademicSetupController::class, 'olevelSubjectsList'])
        ->middleware('academic.resource:olevel');
    Route::middleware('academic.resource:olevel')->group(function () {
        Route::get('/academic/olevel-subjects/import-template', [AcademicSetupController::class, 'importOlevelTemplate']);
        Route::post('/academic/olevel-subjects/import', [AcademicSetupController::class, 'importOlevel']);
        Route::post('/olevel-subjects', [AcademicSetupController::class, 'storeOlevelSubject']);
        Route::patch('/olevel-subjects/{olevelSubject}', [AcademicSetupController::class, 'updateOlevelSubject']);
        Route::delete('/olevel-subjects/{olevelSubject}', [AcademicSetupController::class, 'destroyOlevelSubject']);
    });
    Route::get('/academic/programs', [AcademicSetupController::class, 'programs'])
        ->middleware('academic.resource:programmes,courses,offerings,course-registration,unit-limits,graduation,programme-courses');
    Route::get('/academic/workflow-templates', [AcademicController::class, 'workflowTemplates'])
        ->middleware('academic.resource:programmes');
    Route::middleware('academic.resource:programmes')->group(function () {
        Route::get('/academic/programs/import-template', [AcademicController::class, 'importProgramsTemplate']);
        Route::post('/academic/programs/import', [AcademicController::class, 'importPrograms']);
        Route::post('/programs', [AcademicController::class, 'storeProgram']);
        Route::patch('/programs/{program}', [AcademicController::class, 'updateProgram']);
        Route::delete('/programs/{program}', [AcademicController::class, 'destroyProgram']);
    });
    Route::put('/academic/programs/{program}/courses', [AcademicController::class, 'syncProgramCourses'])
        ->middleware('academic.resource:programme-courses,programmes,courses');
    Route::middleware('academic.resource:courses')->group(function () {
        Route::get('/academic/courses/import-template', [AcademicController::class, 'importTemplate']);
        Route::post('/academic/courses/import', [AcademicController::class, 'importCourses']);
        Route::post('/academic/courses', [AcademicController::class, 'storeCourse']);
        Route::patch('/academic/courses/{course}', [AcademicController::class, 'updateCourse']);
        Route::delete('/academic/courses/{course}', [AcademicController::class, 'destroyCourse']);
    });

    Route::get('/academic/offerings', [CourseOfferingController::class, 'index'])
        ->middleware('academic.resource:offerings,course-registration');
    Route::get('/academic/lecturers', [CourseOfferingController::class, 'lecturers'])
        ->middleware('academic.resource:offerings');
    Route::middleware('academic.resource:offerings')->group(function () {
        Route::post('/academic/offerings', [CourseOfferingController::class, 'store']);
        Route::patch('/academic/offerings/{offering}', [CourseOfferingController::class, 'update']);
        Route::delete('/academic/offerings/{offering}', [CourseOfferingController::class, 'destroy']);
    });

    Route::middleware('academic.resource:course-registration')->group(function () {
        Route::get('/academic/course-registration', [CourseRegistrationController::class, 'context']);
        Route::get('/academic/course-registration/students', [CourseRegistrationController::class, 'searchStudents']);
        Route::post('/academic/course-registration/enroll', [CourseRegistrationController::class, 'staffRegister']);
        Route::delete('/academic/course-registration/enrollments/{enrollment}', [CourseRegistrationController::class, 'staffDrop']);
        Route::post('/academic/course-registration/grace', [CourseRegistrationController::class, 'grantGrace']);
    });

    Route::middleware('academic.resource:unit-limits')->group(function () {
        Route::get('/academic/unit-limits/meta', [UnitLimitController::class, 'meta']);
        Route::get('/academic/unit-limits', [UnitLimitController::class, 'index']);
        Route::post('/academic/unit-limits', [UnitLimitController::class, 'store']);
        Route::patch('/academic/unit-limits/{unitLimit}', [UnitLimitController::class, 'update']);
        Route::delete('/academic/unit-limits/{unitLimit}', [UnitLimitController::class, 'destroy']);
    });

    Route::middleware('academic.resource:registration-extensions')->group(function () {
        Route::get('/academic/registration-extensions', [RegistrationExtensionController::class, 'index']);
        Route::post('/academic/registration-extensions/{extension}/review', [RegistrationExtensionController::class, 'review']);
    });

    Route::middleware('academic.resource:results,results-students,results-import,results-department,results-approvals,results-board,results-release,results-grading-scale')->group(function () {
        Route::get('/academic/results/dashboard', [ResultsController::class, 'dashboard']);
        Route::get('/academic/results/grades', [ResultsController::class, 'index']);
        Route::post('/academic/results/grades', [ResultsController::class, 'store']);
        Route::patch('/academic/results/grades/{grade}', [ResultsController::class, 'update']);
        Route::delete('/academic/results/grades/{grade}', [ResultsController::class, 'destroy']);
        Route::post('/academic/results/submit', [ResultsController::class, 'submit']);
        Route::post('/academic/results/faculty-approve', [ResultsController::class, 'facultyApprove']);
        Route::post('/academic/results/faculty-return', [ResultsController::class, 'facultyReturn']);
        Route::post('/academic/results/board-scopes/clear', [ResultsController::class, 'boardClear']);
        Route::post('/academic/results/board-scopes/request-corrections', [ResultsController::class, 'boardRequestCorrections']);
        Route::post('/academic/results/release', [ResultsController::class, 'release']);
        Route::post('/academic/results/import', [ResultsController::class, 'import']);
        Route::get('/academic/results/students', [ResultsController::class, 'students']);
        Route::get('/academic/results/students/{student}', [ResultsController::class, 'studentGrades']);
        Route::get('/academic/results/students/{student}/transcript', [ResultsController::class, 'staffTranscript']);
        Route::get('/academic/results/grading-scales', [ResultsController::class, 'gradingScales']);
        Route::put('/academic/results/grading-scales/{gradingScale}', [ResultsController::class, 'updateGradingScale']);
        Route::get('/academic/results/reports/submission-list/{scope}', [ResultsController::class, 'submissionList'])
            ->whereIn('scope', ['department', 'faculty', 'board']);
        Route::get('/academic/results/board-lists/{scope}', [ResultsController::class, 'submissionList'])
            ->whereIn('scope', ['department', 'faculty']);
    });

    Route::middleware('permission:academic.offerings.manage')->group(function () {
        Route::get('/course-offerings', [CourseOfferingController::class, 'index']);
        Route::post('/course-offerings', [CourseOfferingController::class, 'store']);
        Route::patch('/course-offerings/{offering}', [CourseOfferingController::class, 'update']);
        Route::delete('/course-offerings/{offering}', [CourseOfferingController::class, 'destroy']);
        Route::get('/course-offerings/meta/lecturers', [CourseOfferingController::class, 'lecturers']);
        Route::get('/course-offerings/meta/courses', [CourseOfferingController::class, 'courses']);
        Route::get('/course-offerings/meta/terms', [CourseOfferingController::class, 'terms']);
    });
    Route::middleware('permission:academic.enrollments.manage')->group(function () {
        Route::get('/course-registration/students', [CourseRegistrationController::class, 'searchStudents']);
        Route::get('/course-registration/context', [CourseRegistrationController::class, 'context']);
        Route::post('/course-registration/register', [CourseRegistrationController::class, 'staffRegister']);
        Route::post('/course-registration/enrollments/{enrollment}/drop', [CourseRegistrationController::class, 'staffDrop']);
        Route::post('/course-registration/grace', [CourseRegistrationController::class, 'grantGrace']);
        Route::get('/unit-limits/meta', [UnitLimitController::class, 'meta']);
        Route::get('/unit-limits', [UnitLimitController::class, 'index']);
        Route::post('/unit-limits', [UnitLimitController::class, 'store']);
        Route::patch('/unit-limits/{unitLimit}', [UnitLimitController::class, 'update']);
        Route::delete('/unit-limits/{unitLimit}', [UnitLimitController::class, 'destroy']);
    });
    Route::middleware('permission:academic.extensions.review')->group(function () {
        Route::get('/registration-extensions', [RegistrationExtensionController::class, 'index']);
        Route::post('/registration-extensions/{extension}/review', [RegistrationExtensionController::class, 'review']);
    });

    Route::middleware('permission:finance.invoices.manage')->group(function () {
        Route::get('/fees/meta', [FinanceController::class, 'feeMeta']);
        Route::get('/fee-categories', [FinanceController::class, 'feeCategories']);
        Route::post('/fee-categories', [FinanceController::class, 'storeFeeCategory']);
        Route::patch('/fee-categories/{feeCategory}', [FinanceController::class, 'updateFeeCategory']);
        Route::delete('/fee-categories/{feeCategory}', [FinanceController::class, 'destroyFeeCategory']);
        Route::post('/fees', [FinanceController::class, 'storeFee']);
        Route::patch('/fees/{fee}', [FinanceController::class, 'updateFee']);
        Route::delete('/fees/{fee}', [FinanceController::class, 'destroyFee']);
        Route::get('/programme-fees', [ProgrammeFeeController::class, 'index']);
        Route::get('/programme-fees/summaries', [ProgrammeFeeController::class, 'summaries']);
        Route::get('/programme-fees/program/{program}', [ProgrammeFeeController::class, 'byProgram']);
        Route::post('/programme-fees/bulk', [ProgrammeFeeController::class, 'bulkStore']);
        Route::post('/programme-fees', [ProgrammeFeeController::class, 'store']);
        Route::patch('/programme-fees/{programmeFee}', [ProgrammeFeeController::class, 'update']);
        Route::delete('/programme-fees/{programmeFee}', [ProgrammeFeeController::class, 'destroy']);
        Route::post('/invoices', [FinanceController::class, 'generate']);
        Route::get('/finance/student-status', [FinanceController::class, 'studentStatus']);
        Route::get('/finance/student-roster', [FinanceController::class, 'studentRoster']);
        Route::get('/finance/student-roster/export', [FinanceController::class, 'exportStudentRoster']);
        Route::post('/invoices/{invoice}/disable', [FinanceController::class, 'disableInvoice']);
        Route::post('/invoices/{invoice}/enable', [FinanceController::class, 'enableInvoice']);
        Route::get('/rebate-types', [RebateController::class, 'types']);
        Route::post('/rebate-types', [RebateController::class, 'storeType']);
        Route::patch('/rebate-types/{rebateType}', [RebateController::class, 'updateType']);
        Route::delete('/rebate-types/{rebateType}', [RebateController::class, 'destroyType']);
        Route::post('/invoices/{invoice}/rebates', [RebateController::class, 'apply']);
        Route::post('/invoices/{invoice}/rebates/{rebate}/reverse', [RebateController::class, 'reverse']);
    });
    Route::post('/invoices/tuition-installment', [FinanceController::class, 'createTuitionInstallment']);
    Route::post('/payments/record', [PaymentController::class, 'record'])->middleware('permission:finance.payments.record');

    Route::get('/me/clinic', [ClinicController::class, 'me']);
    Route::post('/me/clinic/appointments', [ClinicController::class, 'bookAppointment']);
    Route::post('/me/clinic/appointments/{visit}/cancel', [ClinicController::class, 'cancelAppointment']);
    Route::get('/me/hostel', [HostelController::class, 'me']);
    Route::post('/me/hostel/select', [HostelController::class, 'select']);
    Route::get('/clinic/settings', [ClinicController::class, 'settings']);
    Route::put('/clinic/settings', [ClinicController::class, 'updateSettings']);
    Route::get('/clinic/queue', [ClinicController::class, 'queue']);
    Route::post('/clinic/queue', [ClinicController::class, 'checkIn']);
    Route::get('/clinic/appointments', [ClinicController::class, 'appointments']);
    Route::post('/clinic/appointments/{visit}/approve', [ClinicController::class, 'approveAppointment']);
    Route::post('/clinic/appointments/{visit}/reject', [ClinicController::class, 'rejectAppointment']);
    Route::get('/clinic/bills', [ClinicController::class, 'bills']);
    Route::get('/clinic/visits/{visit}', [ClinicController::class, 'showVisit']);
    Route::patch('/clinic/visits/{visit}', [ClinicController::class, 'updateVisit']);
    Route::post('/clinic/visits/{visit}/items', [ClinicController::class, 'addItem']);
    Route::patch('/clinic/visit-items/{item}', [ClinicController::class, 'updateItem']);
    Route::delete('/clinic/visit-items/{item}', [ClinicController::class, 'deleteItem']);
    Route::post('/clinic/visits/{visit}/finalize-bill', [ClinicController::class, 'finalizeBill']);
    Route::get('/clinic/visits/{visit}/preview-split', [ClinicController::class, 'previewSplit']);
    Route::post('/clinic/visits/{visit}/prescriptions', [ClinicController::class, 'addPrescription']);
    Route::patch('/clinic/prescriptions/{prescription}/dispense', [ClinicController::class, 'dispensePrescription']);
    Route::post('/clinic/visits/{visit}/sick-notes', [ClinicController::class, 'addSickNote']);
    Route::get('/clinic/sick-notes/{sickNote}/print', [ClinicController::class, 'printSickNote']);

    Route::get('/medical/nhis', [MedicalController::class, 'nhisRoster']);
    Route::put('/medical/nhis', [MedicalController::class, 'enrolByMatric']);
    Route::get('/medical/{student}', [MedicalController::class, 'profile']);
    Route::put('/medical/{student}', [MedicalController::class, 'updateProfile']);
    Route::post('/medical/{student}/immunizations', [MedicalController::class, 'addImmunization']);
    Route::delete('/medical/immunizations/{immunization}', [MedicalController::class, 'deleteImmunization']);

    Route::get('/hostel-rooms', [HostelController::class, 'rooms'])->middleware('permission:hostel.view');
    Route::get('/hostel-beds', [HostelController::class, 'availableBeds'])->middleware('permission:hostel.view');
    Route::get('/hostel-level-windows', [HostelController::class, 'levelWindows'])->middleware('permission:hostel.view');
    Route::middleware('permission:hostel.manage')->group(function () {
        Route::post('/hostels', [HostelController::class, 'store']);
        Route::patch('/hostels/{hostel}', [HostelController::class, 'update']);
        Route::delete('/hostels/{hostel}', [HostelController::class, 'destroy']);
        Route::put('/hostel-level-windows', [HostelController::class, 'syncLevelWindows']);
        Route::post('/hostels/{hostel}/blocks', [HostelController::class, 'storeBlock']);
        Route::patch('/hostel-blocks/{hostelBlock}', [HostelController::class, 'updateBlock']);
        Route::delete('/hostel-blocks/{hostelBlock}', [HostelController::class, 'destroyBlock']);
        Route::post('/hostel-blocks/{hostelBlock}/rooms', [HostelController::class, 'storeRoom']);
        Route::get('/hostel-rooms/import-template', [HostelController::class, 'importRoomsTemplate']);
        Route::post('/hostel-rooms/import', [HostelController::class, 'importRooms']);
        Route::patch('/hostel-rooms/{hostelRoom}', [HostelController::class, 'updateRoom']);
        Route::delete('/hostel-rooms/{hostelRoom}', [HostelController::class, 'destroyRoom']);
        Route::post('/hostel-rooms/{hostelRoom}/reserve', [HostelController::class, 'reserveRoom']);
        Route::post('/hostel-rooms/{hostelRoom}/release', [HostelController::class, 'releaseRoom']);
        Route::post('/hostel-rooms/{hostelRoom}/disable', [HostelController::class, 'disableRoom']);
        Route::post('/hostel-rooms/{hostelRoom}/enable', [HostelController::class, 'enableRoom']);
    });
    Route::get('/hostel-allocations', [HostelController::class, 'allocations'])->middleware('permission:hostel.view');
    Route::get('/hostel-allocations/export', [HostelController::class, 'exportAllocations'])->middleware('permission:hostel.view');
    Route::get('/hostel-queue', [HostelController::class, 'queue'])->middleware('permission:hostel.view');
    Route::post('/hostel-allocations', [HostelController::class, 'allocate'])->middleware('permission:hostel.allocate');
    Route::post('/hostel-allocations/auto', [HostelController::class, 'autoAllocate'])->middleware('permission:hostel.allocate');
    Route::post('/hostel-allocations/{allocation}/vacate', [HostelController::class, 'vacate'])->middleware('permission:hostel.allocate');
    Route::post('/hostel-allocations/{allocation}/approve', [HostelController::class, 'approve'])->middleware('permission:hostel.allocate');
    Route::post('/hostel-allocations/{allocation}/reject', [HostelController::class, 'reject'])->middleware('permission:hostel.allocate');

    Route::post('/documents', [DocumentController::class, 'issue'])->middleware('permission:documents.issue');

    Route::get('/staff/transcript-requests', [TranscriptRequestController::class, 'index'])
        ->middleware('permission:transcripts.view');
    Route::get('/staff/transcript-requests/{transcriptRequest}', [TranscriptRequestController::class, 'staffShow'])
        ->middleware('permission:transcripts.view');
    Route::get('/staff/transcript-requests/{transcriptRequest}/download', [TranscriptRequestController::class, 'staffDownload'])
        ->middleware('permission:transcripts.view');
    Route::post('/staff/transcript-requests/{transcriptRequest}/start', [TranscriptRequestController::class, 'start'])
        ->middleware('permission:transcripts.process');
    Route::post('/staff/transcript-requests/{transcriptRequest}/ready', [TranscriptRequestController::class, 'ready'])
        ->middleware('permission:transcripts.process');
    Route::post('/staff/transcript-requests/{transcriptRequest}/reject', [TranscriptRequestController::class, 'reject'])
        ->middleware('permission:transcripts.process');

    Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('permission:announcements.manage');
    Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update'])->middleware('permission:announcements.manage');
    Route::post('/announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])->middleware('permission:announcements.manage');
    Route::post('/announcements/{announcement}/unpublish', [AnnouncementController::class, 'unpublish'])->middleware('permission:announcements.manage');
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->middleware('permission:announcements.manage');
    Route::post('/notifications', [NotificationController::class, 'send'])->middleware('permission:notifications.manage');

    Route::get('/audit-logs', [AuditController::class, 'index'])->middleware('permission:audit.view');
    Route::get('/audit-logs/export', [AuditController::class, 'export'])->middleware('permission:audit.view');
    Route::get('/audit-logs/{auditLog}', [AuditController::class, 'show'])->middleware('permission:audit.view');

    Route::middleware(['permission:reports.view', 'portal.nav:reports'])->group(function () {
        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/datasets', [ReportController::class, 'datasets']);
        Route::post('/reports/run', [ReportController::class, 'run']);
        Route::post('/reports/export', [ReportController::class, 'export']);
        Route::get('/reports/saved', [ReportController::class, 'indexSaved']);
        Route::get('/reports/saved/{savedReport}', [ReportController::class, 'showSaved']);
        Route::middleware('permission:reports.manage')->group(function () {
            Route::post('/reports/saved', [ReportController::class, 'storeSaved']);
            Route::patch('/reports/saved/{savedReport}', [ReportController::class, 'updateSaved']);
            Route::delete('/reports/saved/{savedReport}', [ReportController::class, 'destroySaved']);
        });
    });
    Route::get('/integrations', [IntegrationController::class, 'index'])->middleware('permission:integrations.view');
});
