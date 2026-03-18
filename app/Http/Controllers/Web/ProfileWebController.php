<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\RecordsAuditTrail;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\AntivirusScanner;
use App\Services\Security\MalwareScanException;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileWebController extends Controller
{
    use RecordsAuditTrail;

    public function __construct(
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly AntivirusScanner $scanner
    ) {
    }

    public function edit(Request $request): View
    {
        $user = $this->authUser($request);
        $user->loadMissing([
            'direction:id,code,libelle',
            'service:id,code,libelle',
        ]);

        return view('workspace.profile.edit', [
            'user' => $user,
            'profil' => $user->profileInteractions(),
            'passwordPolicyHelp' => $this->passwordPolicy->helpText(),
            'passwordExpired' => $this->passwordPolicy->isExpired($user),
            'activeSessions' => $this->activeSessions($request, $user),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'string', $this->passwordPolicy->rule(false), 'confirmed'],
        ]);

        $payload = [
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
        ];

        $newPassword = isset($validated['password']) && is_string($validated['password']) && $validated['password'] !== ''
            ? (string) $validated['password']
            : null;

        if ($newPassword !== null) {
            $this->passwordPolicy->validateNotReused($user, $newPassword);
        }

        if ($request->hasFile('profile_photo')) {
            try {
                $this->scanner->scanUploadedFile($request->file('profile_photo'));
            } catch (MalwareScanException $exception) {
                return back()->withInput()->withErrors(['profile_photo' => $exception->getMessage()]);
            }

            $newPath = $request->file('profile_photo')?->store('profils', 'public');
            if ($newPath !== null) {
                if (is_string($user->profile_photo_path) && trim($user->profile_photo_path) !== '') {
                    Storage::disk('public')->delete($user->profile_photo_path);
                }
                $payload['profile_photo_path'] = $newPath;
            }
        } elseif ($request->boolean('remove_profile_photo')) {
            if (is_string($user->profile_photo_path) && trim($user->profile_photo_path) !== '') {
                Storage::disk('public')->delete($user->profile_photo_path);
            }
            $payload['profile_photo_path'] = null;
        }

        $before = $user->toArray();
        DB::transaction(function () use ($user, $payload, $newPassword): void {
            $user->fill($payload);
            $user->save();

            if ($newPassword !== null) {
                $this->passwordPolicy->persistPassword($user, $newPassword);
            }
        });
        $user->refresh();

        $this->recordAudit($request, 'profil_utilisateur', 'update', $user, $before, $user->toArray());

        return redirect()
            ->route('workspace.profile.edit')
            ->with('success', 'Profil mis a jour avec succes.');
    }

    public function revokeCurrentSession(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);

        DB::table(config('session.table', 'sessions'))
            ->where('id', $request->session()->getId())
            ->where('user_id', $user->id)
            ->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login.form')
            ->with('success', 'Session courante revoquee.');
    }

    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $user = $this->authUser($request);

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return redirect()
            ->route('workspace.profile.edit')
            ->with('success', 'Toutes les autres sessions ont ete revoquees.');
    }

    public function revokeSession(Request $request, string $sessionId): RedirectResponse
    {
        $user = $this->authUser($request);

        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('id', $sessionId)
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted < 1) {
            return redirect()
                ->route('workspace.profile.edit')
                ->withErrors(['general' => 'Session introuvable ou deja revoquee.']);
        }

        if ($sessionId === $request->session()->getId()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login.form')
                ->with('success', 'Session courante revoquee.');
        }

        return redirect()
            ->route('workspace.profile.edit')
            ->with('success', 'Session revoquee avec succes.');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activeSessions(Request $request, User $user): Collection
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $session) use ($request): array {
                $lastActivity = isset($session->last_activity)
                    ? Carbon::createFromTimestamp((int) $session->last_activity)
                    : null;

                return [
                    'id' => (string) $session->id,
                    'ip_address' => (string) ($session->ip_address ?? 'N/A'),
                    'user_agent' => (string) ($session->user_agent ?? 'Navigateur inconnu'),
                    'last_activity' => $lastActivity,
                    'is_current' => (string) $session->id === $request->session()->getId(),
                ];
            });
    }

    private function authUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
