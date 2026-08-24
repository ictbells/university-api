<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Student;
use App\Services\AuditWriter;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
    public function __construct(private AuditWriter $audit) {}

    public function index(Request $request)
    {
        $query = Document::query()->where('type', '!=', 'id_card')->latest();
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
            'type' => 'required|string|not_in:id_card',
            'title' => 'required|string',
            'html_body' => 'nullable|string',
        ]);
        $student = Student::query()->findOrFail($data['student_id']);

        return $this->officeGate(
            'documents.issue',
            $student,
            $data,
            'Issue document '.$data['title'],
            function () use ($data, $student) {
                $doc = Document::query()->create([
                    ...$data,
                    'user_id' => $student->user_id,
                    'status' => 'issued',
                ]);
                $this->audit->record('document.issued', $doc->title, 'documents', 'document', $doc->id, null, $doc);

                return $doc;
            },
        );
    }
}
