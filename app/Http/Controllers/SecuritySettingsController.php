<?php

namespace App\Http\Controllers;

use App\Support\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecuritySettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(SecuritySettings::all());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'two_factor_enabled' => 'sometimes|boolean',
            'password_rotation_days' => 'sometimes|integer|min:0|max:365',
            'inactivity_logout_minutes' => 'sometimes|integer|min:0|max:1440',
        ]);

        return response()->json(SecuritySettings::update($data));
    }
}
