<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Student;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(private AuditWriter $audit) {}

    public function index(Request $request)
    {
        $query = Document::query()->latest();
        if (! $request->user()->hasPermission('documents.manage') && ! $request->user()->hasPermission('documents.issue')) {
            $query->where(function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
                if ($request->user()->student) {
                    $q->orWhere('student_id', $request->user()->student->id);
                }
            });
        }

        return $query->paginate(25);
    }

    public function issue(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'type' => 'required|string',
            'title' => 'required|string',
            'html_body' => 'nullable|string',
        ]);
        $student = Student::query()->findOrFail($data['student_id']);
        $doc = Document::query()->create([
            ...$data,
            'user_id' => $student->user_id,
            'status' => 'issued',
        ]);
        $student->wallet?->credentials()->create([
            'type' => $doc->type,
            'document_id' => $doc->id,
            'title' => $doc->title,
            'issued_at' => now(),
        ]);
        $this->audit->record('document.issued', $doc->title, 'documents', 'document', $doc->id, null, $doc);

        return $doc;
    }
}
