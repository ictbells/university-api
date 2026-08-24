<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuditListQuery
{
    public static function fromRequest(Request $request): Builder
    {
        $query = AuditLog::query()->orderByDesc('id');

        if ($request->filled('module')) {
            $query->where('module', $request->string('module'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }
        if ($request->filled('actor_email')) {
            $query->where('actor_email', $request->string('actor_email'));
        }
        if ($request->filled('request_id')) {
            $query->where('request_id', $request->string('request_id'));
        }
        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', Carbon::parse((string) $request->input('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', Carbon::parse((string) $request->input('to'))->endOfDay());
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $like = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($like, $search) {
                $builder->where('actor_email', 'like', $like)
                    ->orWhere('actor_name', 'like', $like)
                    ->orWhere('action', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhere('module', 'like', $like)
                    ->orWhere('request_id', 'like', $like)
                    ->orWhere('ip', 'like', $like)
                    ->orWhere('entity_type', 'like', $like)
                    ->orWhere('path', 'like', $like);

                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search)
                        ->orWhere('entity_id', (int) $search);
                }
            });
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    public static function filterSummary(Request $request): array
    {
        $parts = [];
        if ($request->filled('search')) {
            $parts[] = 'Search: '.$request->string('search');
        }
        if ($request->filled('module')) {
            $parts[] = 'Module: '.$request->string('module');
        }
        if ($request->filled('action')) {
            $parts[] = 'Action: '.$request->string('action');
        }
        if ($request->filled('from')) {
            $parts[] = 'From: '.$request->string('from');
        }
        if ($request->filled('to')) {
            $parts[] = 'To: '.$request->string('to');
        }

        return $parts;
    }

    /**
     * @return array{modules: list<string>, actions: list<string>}
     */
    public static function facets(): array
    {
        return [
            'modules' => AuditLog::query()
                ->whereNotNull('module')
                ->where('module', '!=', '')
                ->distinct()
                ->orderBy('module')
                ->pluck('module')
                ->values()
                ->all(),
            'actions' => AuditLog::query()
                ->whereNotNull('action')
                ->where('action', '!=', '')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{actors: int, modules: int}
     */
    public static function summary(Request $request): array
    {
        $base = static::fromRequest($request)->reorder();

        return [
            'actors' => (int) (clone $base)->whereNotNull('actor_email')->distinct()->count('actor_email'),
            'modules' => (int) (clone $base)->whereNotNull('module')->distinct()->count('module'),
        ];
    }
}
