<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterApplicantRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\StaffNavResolver;
use App\Services\StaffOfficePlacement;
use App\Services\StaffSecurityService;
use App\Services\TwoFactorChallengeService;
use App\Support\PasswordRules;
use App\Support\StudentPortalAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private AuditWriter $audit,
        private StaffNavResolver $navResolver,
        private StaffOfficePlacement $placement,
        private StaffSecurityService $security,
        private TwoFactorChallengeService $twoFactor,
    ) {}

    public function register(RegisterApplicantRequest $request): JsonResponse
    {
        $data = $request->validated();
        $applicantRole = Role::query()->where('slug', 'applicant')->where('is_active', true)->firstOrFail();

        $user = User::query()->create([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            'phone' => $data['phone'],
            'jamb_registration' => $data['jamb_registration'],
            'password' => $data['password'],
            'status' => 'active',
        ]);
        $user->roles()->sync([$applicantRole->id]);

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

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'staff_title' => 'nullable|string|max:255',
            'password' => PasswordRules::rules(false),
        ], PasswordRules::messages());

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
        $user->load(['roles.permissions', 'student', 'staff', 'latestApplication.applicationFeeInvoice', 'latestApplication.acceptanceFeeInvoice']);
        $nav = $this->navResolver->resolve($user);
        $staff = $user->staff;
        if ($staff) {
            $staff->setAttribute('office_placement', $this->placement->label($staff));
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
            'nav_unrestricted' => $nav['unrestricted'],
            'nav_link_keys' => $nav['keys'],
            'security' => $this->security->policyPayload($user),
            'token' => $token,
            'university' => [
                'name' => \App\Models\Setting::getValue('university_name', 'Bells University of Technology'),
                'motto' => \App\Models\Setting::getValue('university_motto', 'Chords of Knowledge'),
            ],
        ]);
    }
}
