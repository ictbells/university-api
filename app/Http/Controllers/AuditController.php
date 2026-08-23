<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()->orderByDesc('id');
        foreach (['module', 'action', 'request_id', 'actor_email'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->to);
        }

        return $query->paginate(40);
    }

    public function show(AuditLog $auditLog)
    {
        $row = $auditLog->toArray();
        if (isset($row['before_state']['nin'])) {
            // keep last-4 already stored
        }

        return $row;
    }
}
