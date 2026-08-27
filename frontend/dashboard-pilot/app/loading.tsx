export default function Loading() {
    return (
        <main
            className="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8"
            aria-busy="true"
            aria-label="Chargement du tableau de bord"
        >
            <div className="h-24 animate-pulse rounded-3xl bg-slate-200 dark:bg-slate-800" />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {Array.from({ length: 8 }, (_, index) => (
                    <div
                        className="h-32 animate-pulse rounded-2xl bg-slate-200 dark:bg-slate-800"
                        key={index}
                    />
                ))}
            </div>
            <span className="sr-only">Chargement en cours…</span>
        </main>
    );
}
