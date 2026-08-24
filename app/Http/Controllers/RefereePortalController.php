<?php

namespace App\Http\Controllers;

use App\Services\RefereeInviteService;
use Illuminate\Http\Request;

class RefereePortalController extends Controller
{
    public function __construct(private RefereeInviteService $referees) {}

    public function show(string $token)
    {
        $invite = $this->referees->findByPlainToken($token);
        abort_unless($invite, 403, 'This recommendation link is invalid.');
        if ($invite->isExpired() || $invite->status === 'expired') {
            return response()->json(['message' => 'This recommendation link has expired.', ...$this->referees->publicPayload($invite)], 403);
        }

        return response()->json($this->referees->publicPayload($invite));
    }

    public function store(Request $request, string $token)
    {
        $invite = $this->referees->findByPlainToken($token);
        abort_unless($invite, 403, 'This recommendation link is invalid.');
        abort_unless(! $invite->isExpired() && $invite->status !== 'expired', 403, 'This recommendation link has expired.');
        abort_unless($invite->status !== 'submitted', 422, 'A letter has already been submitted for this invite.');

        $data = $request->validate([
            'file' => 'required|file|max:5120|mimes:pdf,jpg,jpeg,png',
            'comments' => 'nullable|string|max:2000',
        ]);

        $application = $invite->application;
        abort_unless($application, 404);
        $path = $request->file('file')->store('applications/'.$application->id.'/recommendations', 'public');
        $docType = 'recommendation_'.$invite->position;
        $application->documents()->updateOrCreate(
            ['doc_type' => $docType],
            [
                'path' => $path,
                'original_name' => $request->file('file')->getClientOriginalName(),
            ],
        );
        $invite->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'comments' => $data['comments'] ?? null,
        ]);

        return response()->json($this->referees->publicPayload($invite->fresh()));
    }
}
