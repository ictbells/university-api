<?php

namespace App\Http\Controllers;

use App\Models\Lga;
use App\Models\StateOfOrigin;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function states()
    {
        return StateOfOrigin::query()
            ->orderBy('state_title')
            ->get(['state_id', 'state_title']);
    }

    public function lgas(Request $request)
    {
        $data = $request->validate([
            'state_id' => 'required|integer',
        ]);

        return Lga::query()
            ->where('state_id', $data['state_id'])
            ->orderBy('lga_title')
            ->get(['lga_id', 'lga_title', 'state_id']);
    }
}
