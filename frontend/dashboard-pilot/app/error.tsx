'use client';

import { useEffect } from 'react';

type ErrorProperties = {
    error: Error & { digest?: string };
    reset: () => void;
};

export default function ErrorBoundary({ error, reset }: ErrorProperties) {
    useEffect(() => {
        console.error('Dashboard pilot rendering failed', error);
    }, [error]);

    return (
        <main className="grid min-h-screen place-items-center px-4 py-12">
            <section className="w-full max-w-lg rounded-3xl border border-red-200 bg-white p-8 text-center shadow-sm dark:border-red-900 dark:bg-slate-900">
                <p className="text-sm font-semibold uppercase tracking-widest text-red-700 dark:text-red-300">
                    Erreur d’affichage
                </p>
                <h1 className="mt-3 text-2xl font-bold text-slate-950 dark:text-white">
                    Le tableau de bord n’a pas pu être affiché
                </h1>
                <p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
                    Réessayez. Si le problème persiste, revenez à la version actuelle de Pilotage.
                </p>
                <div className="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                    <button
                        className="rounded-xl bg-pilot-700 px-5 py-3 text-sm font-semibold text-white hover:bg-pilot-600 focus-visible:outline-2 focus-visible:outline-pilot-600"
                        onClick={reset}
                        type="button"
                    >
                        Réessayer
                    </button>
                    <a
                        className="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-pilot-600 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        href="/dashboard?dashboardTab=overview"
                    >
                        Ouvrir la version actuelle
                    </a>
                </div>
            </section>
        </main>
    );
}
