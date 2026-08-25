<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterApplicantRequest;
use App\Models\AcademicTerm;
use App\Models\Intake;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApplicationStartService;
use App\Services\AuditWriter;
use App\Services\PremblyService;
use App\Services\StaffNavResolver;
use App\Services\StaffOfficePlacement;
use App\Services\StaffSecurityService;
use App\Services\TwoFactorChallengeService;
use App\Support\ApplicantPassport;
use App\Support\CandidateEligibility;
use App\Support\PasswordRules;
use App\Support\StudentPortalAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuthController extends Controller
{
    private ?AcademicTerm $resolvedCurrentTerm = null;

    private bool $currentTermResolved = false;

    public function __construct(
        private AuditWriter $audit,
        private StaffNavResolver $navResolver,
        private StaffOfficePlacement $placement,
        private StaffSecurityService $security,
        private TwoFactorChallengeService $twoFactor,
        private PremblyService $prembly,
        private ApplicationStartService $applicationStart,
    ) {}

    public function previewNin(Request $request): JsonResponse
    {
        if (! Intake::hasAccepting()) {
            Intake::abortUnlessAccepting();
        }
        $data = $request->validate([
            'nin' => 'required|string',
            'intake_id' => 'required|integer|exists:intakes,id',
        ]);
        Intake::requireAccepting((int) $data['intake_id']);
        $nin = $this->prembly->normalizeNin($data['nin']);
        $this->prembly->assertNinAvailable($nin);
        try {
            $mapped = $this->prembly->lookupIdentity($nin);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['nin' => $e->getMessage()]);
        }

        return response()->json([
            'nin' => $nin,
            'first_name' => $mapped['first_name'],
            'middle_name' => $mapped['middle_name'],
            'last_name' => $mapped['last_name'],
            'date_of_birth' => $mapped['date_of_birth'],
            'gender' => $mapped['gender'],
            'phone' => $mapped['phone'] ?? '',
            'address' => $mapped['address'] ?? '',
            'live' => $this->prembly->isLiveMapped($mapped),
        ]);
    }

    public function register(RegisterApplicantRequest $request): JsonResponse
    {
        $data = $request->validated();
        $intake = Intake::requireAccepting((int) $data['intake_id']);
        CandidateEligibility::assertQualifiedForIntake($intake, $data['jamb_registration'] ?? null);
        $applicantRole = Role::query()->where('slug', 'applicant')->where('is_active', true)->firstOrFail();
        try {
            $mapped = $this->prembly->lookupIdentity($data['nin']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['nin' => $e->getMessage()]);
        }
        $this->prembly->assertNinAvailable($data['nin']);

        try {
            $user = DB::transaction(function () use ($data, $applicantRole, $mapped, $intake) {
                $user = User::query()->create([
                    'name' => $this->prembly->displayName($mapped),
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?: ($mapped['phone'] ?? null),
                    'password' => $data['password'],
                    'status' => 'active',
                ]);
                $user->roles()->sync([$applicantRole->id]);
                $this->prembly->verify($user, null, $data['nin'], $mapped);
                $this->applicationStart->start($user, $intake, $data['jamb_registration'] ?? null);

                return $user;
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['intake_id' => $e->getMessage()]);
        }

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $token = $user->createToken('spa')->plainTextToken;
        $this->audit->record('auth.register', 'Applicant account created', 'auth', 'user', $user->id, null, ['email' => $user->email, 'phone' => $data['phone']]);

        $response = $this->payload($user, $token, 'Registration successful');
        $response->header('X-Auth-Token-Storage', 'sessionStorage');

        return $response;
    }

    public function login(Request $request)
    {
        $portal = $request->input('portal', 'staff');

        if ($portal === 'student') {
            $data = $request->validate([
                'login' => 'required|string',
                'password' => 'required|string',
                'portal' => 'nullable|in:staff,student',
            ]);

            $user = StudentPortalAuth::resolveUser($data['login']);
            if (! $user || ! Hash::check($data['password'], $user->password) || $user->status !== 'active') {
                $this->audit->record('auth.login_failed', 'Failed student portal login', 'auth', 'user', $user?->id, null, ['login' => StudentPortalAuth::normalizeLogin($data['login'])]);
                throw ValidationException::withMessages(['login' => 'These credentials do not match our records.']);
            }

            if (! $user->isStudentPortalUser()) {
                $student = $user->student;
                if ($student && ! \App\Support\Studentship::isCurrent($student)) {
                    $ended = $student->studentship_expires_at?->toDateString()
                        ?: $student->graduated_at?->toDateString();
                    throw ValidationException::withMessages([
                        'login' => $ended
                            ? 'Your studentship ended on '.$ended.'. Sign in is no longer available on the student portal.'
                            : 'Your studentship has ended. Sign in is no longer available on the student portal.',
                    ]);
                }
                throw ValidationException::withMessages([
                    'login' => 'This account uses the staff portal. Sign in at '.rtrim(config('app.frontend_url'), '/'),
                ]);
            }
        } else {
            $data = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
                'portal' => 'nullable|in:staff,student',
            ]);

            $user = User::query()->where('email', $data['email'])->first();
            if (! $user || ! Hash::check($data['password'], $user->password) || $user->status !== 'active') {
                $this->audit->record('auth.login_failed', 'Failed login', 'auth', 'user', $user?->id, null, ['email' => $data['email']]);
                throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
            }

            if (! $user->isStaffPortalUser()) {
                throw ValidationException::withMessages([
                    'email' => 'This account uses the student portal. Sign in at '.rtrim(config('app.student_url'), '/'),
                ]);
            }

            if ($this->security->twoFactorRequired($user)) {
                $setupRequired = ! $this->security->hasTwoFactorConfigured($user);
                $challengeId = $this->twoFactor->create($user, $setupRequired);

                return response()->json([
                    'two_factor_required' => true,
                    'two_factor_setup_required' => $setupRequired,
                    'challenge_id' => $challengeId,
                    'message' => $setupRequired
                        ? 'Set up two-factor authentication to continue.'
                        : 'Enter the code from your authenticator app.',
                ]);
            }

            return $this->completeStaffLogin($request, $user);
        }

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $token = $user->createToken('spa')->plainTextToken;
        $this->audit->record('auth.login', 'User signed in', 'auth', 'user', $user->id);

        $response = $this->payload($user, $token);
        if ($portal === 'student') {
            $response->header('X-Auth-Token-Storage', 'sessionStorage');
        }

        return $response;
    }

    public function completeStaffLogin(Request $request, User $user): JsonResponse
    {
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $this->security->touchActivity($user);
        $token = $user->createToken('spa')->plainTextToken;
        $this->audit->record('auth.login', 'User signed in', 'auth', 'user', $user->id);

        return $this->payload($user, $token);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $user?->currentAccessToken()?->delete();
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        if ($user) {
            $this->audit->record('auth.logout', 'User signed out', 'auth', 'user', $user->id, null, null, null, $user);
        }

        return response()->json(['message' => 'Signed out']);
    }

    public function me(Request $request)
    {
        return $this->payload($request->user());
    }

    public function myPassport(Request $request): BinaryFileResponse
    {
        return ApplicantPassport::fileResponseForUser($request->user());
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'staff_title' => 'nullable|string|max:255',
            'password' => PasswordRules::rules(false),
            'current_password' => 'required_with:password|current_password',
        ], array_merge(PasswordRules::messages(), [
            'current_password.required_with' => 'Enter your current password to set a new one.',
            'current_password.current_password' => 'The current password is incorrect.',
        ]));

        $before = $user->toArray();
        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->password_changed_at = now();
        }
        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }
        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'] ?: null;
        }
        $user->save();

        if ($user->staff && array_key_exists('staff_title', $data)) {
            $user->staff->update(['title' => $data['staff_title']]);
        }

        $this->audit->record('profile.updated', 'Profile updated', 'auth', 'user', $user->id, $before, $user->fresh()->toArray());

        return $this->payload($user->fresh());
    }

    public function forgot(Request $request)
    {
        $portal = $request->input('portal', 'staff');

        if ($portal === 'student') {
            $data = $request->validate([
                'login' => 'required|string',
                'portal' => 'nullable|in:staff,student',
            ]);

            $user = StudentPortalAuth::resolveUser($data['login']);
            if ($user) {
                Password::sendResetLink(['email' => $user->email]);
                $this->audit->record('auth.forgot_password', 'Password reset requested', 'auth', 'user', $user->id, null, ['login' => StudentPortalAuth::normalizeLogin($data['login'])]);
            }

            return response()->json(['message' => 'If that account exists, a reset link was sent to the email on your record.']);
        }

        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));
        $this->audit->record('auth.forgot_password', 'Password reset requested', 'auth', 'user', null, null, ['email' => $request->email]);

        return response()->json(['message' => 'If that email exists, a reset link was sent.']);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => PasswordRules::rules(),
        ], PasswordRules::messages());

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
            ])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }
        $this->audit->record('auth.password_reset', 'Password reset completed', 'auth', 'user', null, null, ['email' => $request->email], 'User reset via email link');

        return response()->json(['message' => 'Password has been reset. You may sign in.']);
    }

    private function payload(User $user, ?string $token = null, ?string $message = null): JsonResponse
    {
        $user->load([
            'roles.permissions',
            'student.program.department.faculty.campus',
            'student.application:id,entry_mode',
            'staff',
            'latestApplication.applicationFeeInvoice',
            'latestApplication.acceptanceFeeInvoice',
            'latestApplication.intake.term',
            'latestNinVerification',
        ]);
        $nav = $this->navResolver->resolve($user);
        $staff = $user->staff;
        if ($staff) {
            $this->placement->enrich($user);
            $staff = $user->staff;
        }

        $ninRecord = $user->latestNinVerification;
        if ($ninRecord) {
            $ninRecord = $this->prembly->ensurePhotoPersisted($user, $ninRecord);
        }

        return response()->json([
            'message' => $message,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'student' => $user->student,
                'staff' => $staff,
            ],
            'roles' => $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]),
            'permissions' => $user->permissions(),
            'role_permissions' => $user->roles
                ->where('is_active', true)
                ->values()
                ->map(fn ($role) => [
                    'role' => ['id' => $role->id, 'name' => $role->name, 'slug' => $role->slug],
                    'permissions' => $role->permissions
                        ->sortBy(['module', 'label'])
                        ->values()
                        ->map(fn ($permission) => [
                            'key' => $permission->key,
                            'label' => $permission->label,
                            'module' => $permission->module,
                        ]),
                ]),
            ...$user->portalAccess(),
            'nin_identity' => $this->prembly->identityPayload($ninRecord),
            'nav_unrestricted' => $nav['unrestricted'],
            'nav_link_keys' => $nav['keys'],
            'is_office_hod' => (bool) ($staff?->is_office_hod),
            'is_office_unit_head' => (bool) ($staff?->is_office_unit_head),
            'security' => $this->security->policyPayload($user),
            'token' => $token,
            'university' => [
                'name' => Setting::getValue('university_name', 'Bells University of Technology'),
                'motto' => Setting::getValue('university_motto', 'Chords of Knowledge'),
            ],
            'current_session' => $this->currentSessionLabel($user),
            'current_semester' => $this->currentSemesterName($user),
            'current_session_kind' => $this->currentSessionKind($user),
            'current_term' => $this->currentTermPayload($user),
        ]);
    }

    private function currentTerm(): ?AcademicTerm
    {
        if (! $this->currentTermResolved) {
            $this->resolvedCurrentTerm = AcademicTerm::query()->with('session')->where('is_current', true)->first();
            $this->currentTermResolved = true;
        }

        return $this->resolvedCurrentTerm;
    }

    private function currentSessionKind(User $user): string
    {
        if (! $user->isStudent() && $user->latestApplication?->intake) {
            return 'application';
        }

        return 'admission';
    }

    private function currentSessionLabel(User $user): ?string
    {
        if ($this->currentSessionKind($user) === 'application') {
            return $user->latestApplication?->intake?->name;
        }

        $current = $this->currentTerm();

        return $current?->session?->label
            ?: $current?->session_label
            ?: Setting::getValue('current_session_label')
            ?: $user->latestApplication?->intake?->term?->session_label;
    }

    private function currentSemesterName(?User $user = null): ?string
    {
        if ($user && $this->currentSessionKind($user) === 'application') {
            return null;
        }

        return $this->currentTerm()?->name;
    }

    private function currentTermPayload(User $user): ?array
    {
        $term = $this->currentTerm();
        if (! $term) {
            $label = $this->currentSessionLabel($user);
            $semester = $this->currentSemesterName($user);
            if (! $label && ! $semester) {
                return null;
            }

            return [
                'id' => null,
                'name' => $semester,
                'session_label' => $label,
                'registration_status' => 'Closed',
                'normal_registration_closes_at' => null,
                'late_registration_closes_at' => null,
            ];
        }

        return [
            'id' => $term->id,
            'name' => $term->name,
            'session_label' => $term->session?->label ?: $term->session_label,
            'registration_status' => $term->registrationStatus(),
            'normal_registration_closes_at' => optional($term->normal_registration_closes_at)?->toIso8601String(),
            'late_registration_closes_at' => optional($term->late_registration_closes_at)?->toIso8601String(),
        ];
    }
}
