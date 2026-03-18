<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationWebController extends Controller
{
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

        return back()->with('success', 'Toutes les notifications ont ete marquees comme lues.');
    }
}
