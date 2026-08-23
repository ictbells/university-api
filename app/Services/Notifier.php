<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;

class Notifier
{
    public function send(User $user, string $type, string $title, ?string $body = null, ?string $module = null, ?int $relatedId = null): AppNotification
    {
        return AppNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'module' => $module,
            'related_id' => $relatedId,
        ]);
    }
}
