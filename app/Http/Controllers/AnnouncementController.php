<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index()
    {
        return Announcement::query()->latest()->get();
    }

    public function store(Request $request)
    {
        return Announcement::query()->create($request->validate([
            'title' => 'required|string',
            'body' => 'required|string',
            'audience' => 'nullable|string',
        ]) + [
            'created_by' => $request->user()->id,
            'published_at' => now(),
        ]);
    }
}
