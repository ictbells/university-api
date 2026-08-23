<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AcademicSetupController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\HostelController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\MedicalController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfficeStructureController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PgController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SecuritySettingsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgot']);
Route::post('/reset-password', [AuthController::class, 'reset']);
Route::post('/two-factor/setup', [TwoFactorController::class, 'setup']);
Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm']);
Route::post('/two-factor/verify', [TwoFactorController::class, 'verify']);
Route::post('/payments/paystack/webhook', [PaymentController::class, 'webhook']);

Route::get('/intakes', [AcademicSetupController::class, 'openIntakes']);
Route::get('/programs', [AcademicController::class, 'programs']);
Route::get('/academic-levels', [AcademicSetupController::class, 'levels']);
Route::get('/olevel-subjects', [AcademicSetupController::class, 'olevelSubjects']);

Route::middleware(['auth:sanctum', 'staff.security'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);

    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::post('/applications', [ApplicationController::class, 'start']);
    Route::get('/applications/{application}', [ApplicationController::class, 'show']);
    Route::post('/applications/{application}/steps', [ApplicationController::class, 'saveStep']);
    Route::post('/applications/{application}/submit', [ApplicationController::class, 'submit']);
    Route::post('/applications/{application}/documents', [ApplicationController::class, 'uploadDocument']);
    Route::post('/applications/{application}/nin', [ApplicationController::class, 'verifyNin']);
    Route::post('/applications/{application}/transition', [ApplicationController::class, 'transition']);

    Route::get('/registrations', [RegistrationController::class, 'index'])
        ->middleware('permission:registrations.view');

    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{student}', [StudentController::class, 'show']);
    Route::patch('/students/{student}', [StudentController::class, 'update']);

    Route::get('/wallet', [WalletController::class, 'show']);
    Route::post('/wallet/pay/{invoice}', [WalletController::class, 'payInvoice']);
    Route::post('/wallet/topup', [WalletController::class, 'topup']);

    Route::get('/invoices', [FinanceController::class, 'invoices']);
    Route::get('/fees', [FinanceController::class, 'fees']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments/paystack/initialize', [PaymentController::class, 'initialize']);
    Route::get('/payments/paystack/verify/{reference}', [PaymentController::class, 'verify']);

    Route::get('/academic/courses', [AcademicController::class, 'courses'])
        ->middleware('academic.resource:courses,programmes');
    Route::get('/academic/my-enrollments', [AcademicController::class, 'myEnrollments']);
    Route::get('/academic/transcript/{student?}', [AcademicController::class, 'transcript']);

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
        ->middleware('academic.resource:campuses,colleges');
    Route::middleware('academic.resource:campuses')->group(function () {
        Route::post('/campuses', [InstitutionController::class, 'storeCampus']);
        Route::patch('/campuses/{campus}', [InstitutionController::class, 'updateCampus']);
        Route::delete('/campuses/{campus}', [InstitutionController::class, 'destroyCampus']);
    });
    Route::get('/academic/faculties', [AcademicSetupController::class, 'faculties'])
        ->middleware('academic.resource:colleges,departments');
    Route::middleware('academic.resource:colleges')->group(function () {
        Route::post('/faculties', [InstitutionController::class, 'storeFaculty']);
        Route::patch('/faculties/{faculty}', [InstitutionController::class, 'updateFaculty']);
        Route::delete('/faculties/{faculty}', [InstitutionController::class, 'destroyFaculty']);
    });
    Route::get('/academic/departments', [AcademicSetupController::class, 'departments'])
        ->middleware('academic.resource:departments,programmes,courses');
    Route::middleware('academic.resource:departments')->group(function () {
        Route::post('/departments', [InstitutionController::class, 'storeDepartment']);
        Route::patch('/departments/{department}', [InstitutionController::class, 'updateDepartment']);
        Route::delete('/departments/{department}', [InstitutionController::class, 'destroyDepartment']);
    });
    Route::get('/academic/terms', [AcademicSetupController::class, 'terms'])
        ->middleware('academic.resource:sessions,intakes');
    Route::middleware('academic.resource:sessions')->group(function () {
        Route::post('/terms', [InstitutionController::class, 'storeTerm']);
        Route::patch('/terms/{term}', [InstitutionController::class, 'updateTerm']);
        Route::delete('/terms/{term}', [InstitutionController::class, 'destroyTerm']);
    });
    Route::get('/academic/levels', [AcademicSetupController::class, 'levelsList'])
        ->middleware('academic.resource:levels');
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
        Route::post('/olevel-subjects', [AcademicSetupController::class, 'storeOlevelSubject']);
        Route::patch('/olevel-subjects/{olevelSubject}', [AcademicSetupController::class, 'updateOlevelSubject']);
        Route::delete('/olevel-subjects/{olevelSubject}', [AcademicSetupController::class, 'destroyOlevelSubject']);
    });
    Route::get('/academic/programs', [AcademicSetupController::class, 'programs'])
        ->middleware('academic.resource:programmes,courses');
    Route::middleware('academic.resource:programmes')->group(function () {
        Route::post('/programs', [AcademicController::class, 'storeProgram']);
        Route::patch('/programs/{program}', [AcademicController::class, 'updateProgram']);
        Route::delete('/programs/{program}', [AcademicController::class, 'destroyProgram']);
    });
    Route::middleware('academic.resource:courses')->group(function () {
        Route::post('/academic/courses', [AcademicController::class, 'storeCourse']);
        Route::patch('/academic/courses/{course}', [AcademicController::class, 'updateCourse']);
        Route::delete('/academic/courses/{course}', [AcademicController::class, 'destroyCourse']);
    });

    Route::middleware('permission:finance.invoices.manage')->group(function () {
        Route::post('/fees', [FinanceController::class, 'storeFee']);
        Route::post('/invoices', [FinanceController::class, 'generate']);
    });
    Route::post('/payments/record', [PaymentController::class, 'record'])->middleware('permission:finance.payments.record');

    Route::get('/medical/{student}', [MedicalController::class, 'profile']);
    Route::put('/medical/{student}', [MedicalController::class, 'updateProfile']);
    Route::post('/medical/{student}/immunizations', [MedicalController::class, 'addImmunization']);
    Route::post('/clinic-visits', [MedicalController::class, 'visit'])->middleware('permission:medical.manage');

    Route::get('/hostel-rooms', [HostelController::class, 'rooms'])->middleware('permission:hostel.view');
    Route::middleware('permission:hostel.manage')->group(function () {
        Route::post('/hostels', [HostelController::class, 'store']);
        Route::patch('/hostels/{hostel}', [HostelController::class, 'update']);
        Route::get('/hostel-level-windows', [HostelController::class, 'levelWindows']);
        Route::put('/hostel-level-windows', [HostelController::class, 'syncLevelWindows']);
        Route::post('/hostels/{hostel}/blocks', [HostelController::class, 'storeBlock']);
        Route::post('/hostel-blocks/{hostelBlock}/rooms', [HostelController::class, 'storeRoom']);
        Route::patch('/hostel-rooms/{hostelRoom}', [HostelController::class, 'updateRoom']);
        Route::post('/hostel-rooms/{hostelRoom}/reserve', [HostelController::class, 'reserveRoom']);
        Route::post('/hostel-rooms/{hostelRoom}/release', [HostelController::class, 'releaseRoom']);
        Route::post('/hostel-rooms/{hostelRoom}/disable', [HostelController::class, 'disableRoom']);
        Route::post('/hostel-rooms/{hostelRoom}/enable', [HostelController::class, 'enableRoom']);
    });
    Route::get('/hostel-allocations', [HostelController::class, 'allocations'])->middleware('permission:hostel.view');
    Route::get('/hostel-queue', [HostelController::class, 'queue'])->middleware('permission:hostel.view');
    Route::post('/hostel-allocations', [HostelController::class, 'allocate'])->middleware('permission:hostel.allocate');
    Route::post('/hostel-allocations/auto', [HostelController::class, 'autoAllocate'])->middleware('permission:hostel.allocate');
    Route::post('/hostel-allocations/{allocation}/vacate', [HostelController::class, 'vacate'])->middleware('permission:hostel.allocate');

    Route::post('/documents', [DocumentController::class, 'issue'])->middleware('permission:documents.issue');

    Route::get('/pg-records', [PgController::class, 'index'])->middleware('permission:pg.view');
    Route::patch('/pg-records/{pgRecord}', [PgController::class, 'update'])->middleware('permission:pg.manage');

    Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('permission:announcements.manage');
    Route::post('/notifications', [NotificationController::class, 'send'])->middleware('permission:notifications.manage');

    Route::get('/audit-logs', [AuditController::class, 'index'])->middleware('permission:audit.view');
    Route::get('/audit-logs/{auditLog}', [AuditController::class, 'show'])->middleware('permission:audit.view');

    Route::get('/reports/summary', [ReportController::class, 'summary'])->middleware('permission:reports.view');
    Route::get('/integrations', [IntegrationController::class, 'index'])->middleware('permission:integrations.view');
});
