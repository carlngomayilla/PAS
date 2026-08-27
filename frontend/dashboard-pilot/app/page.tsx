import { connection } from 'next/server';
import { DashboardPage } from '@/components/dashboard-page';
import { fetchDashboardOverview } from '@/lib/dashboard-api';

export const dynamic = 'force-dynamic';
export const revalidate = 0;
export const runtime = 'nodejs';

type PageProperties = {
    searchParams: Promise<Record<string, string | string[] | undefined>>;
};

export default async function Page({ searchParams }: PageProperties) {
    await connection();

    const result = await fetchDashboardOverview(await searchParams);

    return <DashboardPage result={result} />;
}
