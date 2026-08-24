<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditWriter
{
    public function record(
        string $action,
        string $summary,
        string $module,
        ?string $entityType = null,
        ?int $entityId = null,
        mixed $before = null,
        mixed $after = null,
        ?string $reason = null,
        ?User $actor = null,
    ): AuditLog {
        $request = request();
        $actor = $actor ?: Auth::user();
        $prev = AuditLog::query()->orderByDesc('id')->first();
        $occurredAt = now();

        $payload = [
            'actor_type' => $actor ? 'user' : 'system',
            'actor_user_id' => $actor?->id,
            'actor_email' => $actor?->email,
            'actor_name' => $actor?->name,
            'actor_roles' => $actor?->roles->pluck('slug')->all(),
            'action' => $action,
            'summary' => $summary,
            'occurred_at' => $occurredAt->toIso8601String(),
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'route' => $request instanceof Request ? optional($request->route())->getName() : null,
            'path' => $request instanceof Request ? $request->path() : null,
            'before_state' => $this->redact($before),
            'after_state' => $this->redact($after),
            'ip' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? $request->userAgent() : null,
            'device' => $request instanceof Request ? $this->device($request->userAgent()) : null,
            'request_id' => ($request instanceof Request ? ($request->attributes->get('request_id') ?: $request->header('X-Request-Id')) : null) ?: (string) Str::uuid(),
            'reason' => $reason,
            'prev_hash' => $prev?->row_hash,
        ];

        $payload['row_hash'] = hash('sha256', json_encode($payload));

        return AuditLog::query()->create([
            ...$payload,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function redact(mixed $data): mixed
    {
        if ($data === null) {
            return null;
        }
        $array = is_array($data) ? $data : (method_exists($data, 'toArray') ? $data->toArray() : (array) $data);
        foreach (['password', 'password_confirmation', 'current_password', 'remember_token', 'token'] as $secret) {
            unset($array[$secret]);
        }
        if (isset($array['nin']) && is_string($array['nin']) && strlen($array['nin']) > 4) {
            $array['nin'] = str_repeat('*', strlen($array['nin']) - 4).substr($array['nin'], -4);
        }

        return $array;
    }

    private function device(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }
        $browser = 'Unknown';
        if (str_contains($ua, 'Edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'Chrome/')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'Firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'Safari/')) {
            $browser = 'Safari';
        }
        $os = 'Unknown';
        if (str_contains($ua, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($ua, 'Mac OS')) {
            $os = 'macOS';
        } elseif (str_contains($ua, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            $os = 'iOS';
        } elseif (str_contains($ua, 'Linux')) {
            $os = 'Linux';
        }

        return $browser.' on '.$os;
    }
}
