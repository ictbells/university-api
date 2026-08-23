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
        return AppNotification::query()->where('user_id', $request->user()->id)->latest()->paginate(20);
    }

    public function markRead(AppNotification $notification, Request $request)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return $notification;
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
