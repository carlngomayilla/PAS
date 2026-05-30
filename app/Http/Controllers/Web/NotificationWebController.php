<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class NotificationWebController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $notifications = $user->notifications()
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('workspace.notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $record = $user->notifications()
            ->whereKey($notification)
            ->firstOrFail();

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        $targetUrl = (string) ($record->data['url'] ?? route('dashboard'));

        return redirect()->to($targetUrl);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $user->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'Toutes les notifications ont été marquées comme lues.');
    }
}
