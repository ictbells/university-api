<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;
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

        return $this->officeGate('announcements.store', null, $data, 'Create announcement', fn () => $this->announcements->create($request->user(), $data));
    }

    public function update(Request $request, Announcement $announcement)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'body' => 'sometimes|required|string',
            'audience' => 'sometimes|required|in:'.implode(',', AnnouncementService::AUDIENCES),
        ]);

        return $this->officeGate('announcements.update', $announcement, ['announcement_id' => $announcement->id, ...$data], 'Update announcement', fn () => $this->announcements->update($announcement, $data));
    }

    public function publish(Announcement $announcement)
    {
        return $this->officeGate('announcements.publish', $announcement, ['announcement_id' => $announcement->id], 'Publish announcement', fn () => $this->announcements->publish($announcement));
    }

    public function unpublish(Announcement $announcement)
    {
        return $this->officeGate('announcements.unpublish', $announcement, ['announcement_id' => $announcement->id], 'Unpublish announcement', fn () => $this->announcements->unpublish($announcement));
    }

    public function destroy(Announcement $announcement)
    {
        return $this->officeGate('announcements.destroy', $announcement, ['announcement_id' => $announcement->id], 'Delete announcement', function () use ($announcement) {
            $this->announcements->delete($announcement);

            return response()->noContent();
        });
    }
}
