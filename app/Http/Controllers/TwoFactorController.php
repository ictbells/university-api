<?php

namespace App\Http\Controllers;

use App\Services\StaffSecurityService;
use App\Services\TwoFactorChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(
        private TwoFactorChallengeService $challenges,
        private StaffSecurityService $security,
        private AuthController $auth,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge_id' => 'required|uuid']);

        $user = $this->challenges->user($data['challenge_id']);
        if (! $user || ! $this->security->twoFactorRequired($user)) {
            throw ValidationException::withMessages(['challenge_id' => 'This verification session is invalid or has expired.']);
        }

        $setup = $this->challenges->beginSetup($data['challenge_id']);
        if (! $setup) {
            throw ValidationException::withMessages(['challenge_id' => 'This verification session is invalid or has expired.']);
        }

        return response()->json($setup);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => 'required|uuid',
            'code' => 'required|string|size:6',
        ]);

        $user = $this->challenges->confirm($data['challenge_id'], $data['code']);
        if (! $user) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid.']);
        }

        return $this->auth->completeStaffLogin($request, $user);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => 'required|uuid',
            'code' => 'required|string|size:6',
        ]);

        $user = $this->challenges->verifyLogin($data['challenge_id'], $data['code']);
        if (! $user) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid.']);
        }

        return $this->auth->completeStaffLogin($request, $user);
    }
}
