<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(private AnnouncementService $announcements) {}

    public function index(Request $request)
    {
        if ($request->filled('limit')) {
            return $this->announcements->listFor($request->user(), $request->integer('limit'));
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 50);
        $page = $this->announcements->paginateFor($request->user(), $perPage);
        $counts = $this->announcements->statusCounts($request->user());

        return response()->json($page->toArray() + [
            'published_count' => $counts['published'],
            'draft_count' => $counts['drafts'],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'audience' => 'required|in:'.implode(',', AnnouncementService::AUDIENCES),
            'publish' => 'sometimes|boolean',
        ]);

        return $this->announcements->create($request->user(), $data);
    }

    public function update(Request $request, Announcement $announcement)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'body' => 'sometimes|required|string',
            'audience' => 'sometimes|required|in:'.implode(',', AnnouncementService::AUDIENCES),
        ]);

        return $this->announcements->update($announcement, $data);
    }

    public function publish(Announcement $announcement)
    {
        return $this->announcements->publish($announcement);
    }

    public function unpublish(Announcement $announcement)
    {
        return $this->announcements->unpublish($announcement);
    }

    public function destroy(Announcement $announcement)
    {
        $this->announcements->delete($announcement);

        return response()->noContent();
    }
}
