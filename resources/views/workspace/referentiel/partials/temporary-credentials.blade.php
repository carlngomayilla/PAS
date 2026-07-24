@php
    $temporaryCredentials = collect(session('temporary_credentials', []));
    if (session()->has('temporary_password_value')) {
        $temporaryCredentials->prepend([
            'user' => (string) session('temporary_password_user', ''),
            'password' => (string) session('temporary_password_value'),
        ]);
    }
@endphp

@if ($temporaryCredentials->isNotEmpty())
    <section class="app-screen-block rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-700 dark:bg-amber-950/45 dark:text-amber-100" role="status" aria-labelledby="temporary-credentials-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="temporary-credentials-title" class="text-base font-bold">Identifiants temporaires</h2>
                <p class="mt-1 text-sm">Affichage unique. Chaque compte devra renouveler son mot de passe à la prochaine connexion.</p>
            </div>
            <span class="rounded-full border border-amber-300 px-2.5 py-1 text-xs font-bold dark:border-amber-700">{{ $temporaryCredentials->count() }} compte(s)</span>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[36rem] text-left text-sm">
                <thead class="border-b border-amber-200 text-xs uppercase dark:border-amber-800">
                    <tr><th class="px-2 py-2">Utilisateur</th><th class="px-2 py-2">Mot de passe temporaire</th></tr>
                </thead>
                <tbody class="divide-y divide-amber-200 dark:divide-amber-800">
                    @foreach ($temporaryCredentials as $credential)
                        <tr>
                            <td class="px-2 py-2 font-semibold">{{ $credential['user'] ?? '' }}</td>
                            <td class="px-2 py-2"><code class="select-all rounded bg-white px-2 py-1 font-mono text-slate-950 dark:bg-slate-900 dark:text-slate-100">{{ $credential['password'] ?? '' }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
