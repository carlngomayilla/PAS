import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';
import '@/app/globals.css';

export const metadata: Metadata = {
    title: 'Pilotage | PAS',
    description: 'Vue de pilotage opérationnel du PAS',
    robots: {
        index: false,
        follow: false,
        nocache: true,
    },
};

export const viewport: Viewport = {
    width: 'device-width',
    initialScale: 1,
    colorScheme: 'light dark',
};

export default function RootLayout({ children }: Readonly<{ children: ReactNode }>) {
    return (
        <html lang="fr">
            <body>{children}</body>
        </html>
    );
}
