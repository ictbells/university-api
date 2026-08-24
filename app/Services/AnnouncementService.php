<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AnnouncementService
{
    public const AUDIENCES = ['students', 'applicants', 'students_and_applicants'];

    public function __construct(
        private AuditWriter $audit,
        private Notifier $notifier,
    ) {}

    public function listFor(User $user, ?int $limit = null): Collection
    {
        $query = $this->visibleQuery($user)->latest();

        if ($limit) {
            $query->limit(min(max($limit, 1), 50));
        }

        return $query->get();
    }

    public function paginateFor(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $this->visibleQuery($user)->latest()->paginate(min(max($perPage, 1), 50));
    }

    /** @return array{published: int, drafts: int} */
    public function statusCounts(User $user): array
    {
        $query = $this->visibleQuery($user);

        return [
            'published' => (clone $query)->whereNotNull('published_at')->count(),
            'drafts' => (clone $query)->whereNull('published_at')->count(),
        ];
    }

    public function create(User $actor, array $data): Announcement
    {
        $publish = array_key_exists('publish', $data) ? (bool) $data['publish'] : true;

        $announcement = Announcement::query()->create([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'created_by' => $actor->id,
            'published_at' => $publish ? now() : null,
        ]);

        $this->audit->record(
            'announcement.created',
            $publish ? 'Announcement published' : 'Announcement draft created',
            'announcements',
            'announcement',
            $announcement->id,
            null,
            $announcement,
        );

        if ($publish) {
            $this->notifyAudience($announcement);
        }

        return $announcement;
    }

    public function update(Announcement $announcement, array $data): Announcement
    {
        $before = $announcement->replicate();
        $announcement->fill(array_filter([
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'audience' => $data['audience'] ?? null,
        ], fn ($value) => $value !== null))->save();

        $fresh = $announcement->fresh();
        $this->audit->record(
            'announcement.updated',
            'Announcement updated',
            'announcements',
            'announcement',
            $announcement->id,
            $before,
            $fresh,
        );

        return $fresh;
    }

    public function publish(Announcement $announcement): Announcement
    {
        if ($announcement->published_at) {
            return $announcement;
        }

        $before = $announcement->replicate();
        $announcement->update(['published_at' => now()]);
        $fresh = $announcement->fresh();

        $this->audit->record(
            'announcement.published',
            'Announcement published',
            'announcements',
            'announcement',
            $announcement->id,
            $before,
            $fresh,
        );

        $this->notifyAudience($fresh);

        return $fresh;
    }

    public function unpublish(Announcement $announcement): Announcement
    {
        abort_unless($announcement->published_at, 422, 'This announcement is not published.');

        $before = $announcement->replicate();
        $announcement->update(['published_at' => null]);
        $fresh = $announcement->fresh();

        $this->audit->record(
            'announcement.unpublished',
            'Announcement unpublished',
            'announcements',
            'announcement',
            $announcement->id,
            $before,
            $fresh,
        );

        return $fresh;
    }

    public function delete(Announcement $announcement): void
    {
        $before = $announcement->toArray();
        $id = $announcement->id;
        $announcement->delete();

        $this->audit->record(
            'announcement.deleted',
            'Announcement deleted',
            'announcements',
            'announcement',
            $id,
            $before,
            null,
        );
    }

    public function notifyAudience(Announcement $announcement): void
    {
        $excerpt = Str::limit(strip_tags((string) $announcement->body), 240);

        $this->audienceQuery($announcement->audience)
            ->chunkById(200, function ($users) use ($announcement, $excerpt) {
                foreach ($users as $user) {
                    $this->notifier->send(
                        $user,
                        'announcement',
                        $announcement->title,
                        $excerpt,
                        'announcements',
                        $announcement->id,
                    );
                }
            });
    }

    /** @return list<string> */
    public function visibleAudiencesFor(User $user): array
    {
        $audiences = ['students_and_applicants', 'all'];

        if ($user->student()->exists()) {
            $audiences[] = 'students';
        } elseif ($user->applications()->exists()) {
            $audiences[] = 'applicants';
        }

        return $audiences;
    }

    private function visibleQuery(User $user): Builder
    {
        $query = Announcement::query();

        if (! $this->seesAll($user)) {
            $query->whereNotNull('published_at')
                ->whereIn('audience', $this->visibleAudiencesFor($user));
        }

        return $query;
    }

    private function seesAll(User $user): bool
    {
        return $user->isStaffPortalUser() || $user->hasPermission('announcements.manage');
    }

    private function audienceQuery(string $audience): Builder
    {
        $query = User::query();

        return match ($audience) {
            'students' => $query->whereHas('student'),
            'applicants' => $query->whereDoesntHave('student')->whereHas('applications'),
            default => $query->where(function (Builder $inner) {
                $inner->whereHas('student')
                    ->orWhere(fn (Builder $q) => $q->whereDoesntHave('student')->whereHas('applications'));
            }),
        };
    }
}
