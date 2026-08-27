import type { DashboardFetchResult } from '@/lib/dashboard-api';

type FailureResult = Exclude<DashboardFetchResult, { ok: true }>;

const statusTitles: Record<FailureResult['kind'], string> = {
    unauthenticated: 'Connexion requise',
    forbidden: 'Accès non autorisé',
    expired: 'Session expirée',
    validation: 'Filtres non valides',
    unavailable: 'Service indisponible',
    'invalid-response': 'Réponse inattendue',
};

export function DashboardStatusPanel({ result }: { result: FailureResult }) {
    return (
        <main className="grid min-h-screen place-items-center px-4 py-12">
            <section
                aria-live="polite"
                className="w-full max-w-xl rounded-3xl border border-amber-200 bg-white p-8 shadow-sm dark:border-amber-900 dark:bg-slate-900"
            >
                <p className="text-sm font-bold uppercase tracking-widest text-amber-700 dark:text-amber-300">
                    {result.status > 0 ? `Erreur ${result.status}` : 'Connexion'}
                </p>
                <h1 className="mt-3 text-2xl font-bold text-slate-950 dark:text-white">
                    {statusTitles[result.kind]}
                </h1>
                <p className="mt-3 leading-7 text-slate-600 dark:text-slate-300">
                    {result.message}
                </p>
                {result.errors.length > 0 ? (
                    <ul className="mt-5 list-disc space-y-2 rounded-2xl bg-amber-50 p-5 pl-10 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100">
                        {result.errors.map((message) => <li key={message}>{message}</li>)}
                    </ul>
                ) : null}
                <div className="mt-7 flex flex-col gap-3 sm:flex-row">
                    <a
                        className="rounded-xl bg-pilot-700 px-5 py-3 text-center text-sm font-semibold text-white hover:bg-pilot-600 focus-visible:outline-2 focus-visible:outline-pilot-600"
                        href={result.kind === 'unauthenticated' || result.kind === 'expired' ? '/login' : '/dashboard-pilot'}
                    >
                        {result.kind === 'unauthenticated' || result.kind === 'expired'
                            ? 'Se connecter'
                            : 'Réessayer'}
                    </a>
                    <a
                        className="rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-pilot-600 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        href="/dashboard?dashboardTab=overview"
                    >
                        Version actuelle de Pilotage
                    </a>
                </div>
            </section>
        </main>
    );
}
