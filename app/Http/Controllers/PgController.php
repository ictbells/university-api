<?php

namespace App\Http\Controllers;

use App\Models\PgRecord;
use Illuminate\Http\Request;

class PgController extends Controller
{
    public function index()
    {
        return PgRecord::query()->with(['student.user', 'supervisor.user'])->get();
    }

    public function update(Request $request, PgRecord $pgRecord)
    {
        $pgRecord->update($request->validate([
            'supervisor_staff_id' => 'nullable|exists:staff,id',
            'topic' => 'nullable|string',
            'proposal_status' => 'nullable|string',
            'thesis_status' => 'nullable|string',
        ]));

        return $pgRecord->fresh(['student', 'supervisor.user']);
    }
}
