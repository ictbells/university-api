<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 50);

        return AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($perPage);
    }

    public function unreadCount(Request $request)
    {
        $count = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return ['count' => $count];
    }

    public function markRead(AppNotification $notification, Request $request)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return $notification;
    }

    public function markAllRead(Request $request)
    {
        AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ['ok' => true];
    }

    public function send(Request $request, Notifier $notifier)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string',
            'body' => 'nullable|string',
            'type' => 'nullable|string',
        ]);
        $user = User::query()->findOrFail($data['user_id']);

        return $notifier->send($user, $data['type'] ?? 'notice', $data['title'], $data['body'] ?? null, 'notifications');
    }
}
