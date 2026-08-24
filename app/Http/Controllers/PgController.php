<?php

namespace App\Http\Controllers;

use App\Models\PgRecord;
use Illuminate\Http\Request;

class PgController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    public function index()
    {
        return PgRecord::query()->with(['student.user', 'supervisor.user'])->get();
    }

    public function update(Request $request, PgRecord $pgRecord)
    {
        $data = $request->validate([
            'supervisor_staff_id' => 'nullable|exists:staff,id',
            'topic' => 'nullable|string',
            'proposal_status' => 'nullable|string',
            'thesis_status' => 'nullable|string',
        ]);

        return $this->officeGate('pg.update', $pgRecord, ['pg_record_id' => $pgRecord->id, ...$data], 'Update PG record', function () use ($pgRecord, $data) {
            $pgRecord->update($data);

            return $pgRecord->fresh(['student', 'supervisor.user']);
        });
    }
}
