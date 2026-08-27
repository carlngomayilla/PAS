import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import { DashboardPage } from '@/components/dashboard-page';
import type { DashboardFetchResult } from '@/lib/dashboard-api';
import { dashboardFixture } from '@/tests/fixture';
import {
    resetNavigationMock,
    setNavigationSearchParameters,
} from '@/tests/mocks/next-navigation';

describe('dashboard page', () => {
    beforeEach(() => {
        resetNavigationMock();
    });

    it('renders ordered tabs and clickable metrics using Laravel links', () => {
        const data = dashboardFixture();
        setNavigationSearchParameters('periode=q2&direction_id=1');

        render(<DashboardPage result={{ ok: true, data }} />);

        const navigation = screen.getByRole('navigation', {
            name: 'Sections du tableau de bord',
        });
        expect(within(navigation).getAllByRole('link').map((link) => link.textContent?.trim()))
            .toEqual(['Pilotage', 'Tableaux', 'Graphiques']);
        expect(within(navigation).getByRole('link', { name: 'Pilotage' }))
            .toHaveAttribute('href', '/?periode=q2&direction_id=1');
        expect(screen.getByRole('link', { name: 'Actions : ouvrir le détail' }))
            .toHaveAttribute('href', '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced');
        expect(screen.getByRole('link', { name: 'PAS : ouvrir le détail' }))
            .toHaveAttribute('href', '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced');
        expect(screen.getByText('Contrôle et suivi')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Alertes actives : ouvrir le détail' }))
            .toHaveAttribute('href', '/workspace/notifications');

        const actionArticle = screen.getByRole('heading', { name: 'Avancement des actions' })
            .closest('article');
        expect(actionArticle).not.toBeNull();
        expect(within(actionArticle as HTMLElement).getByText('Acheve').closest('a'))
            .toHaveAttribute(
                'href',
                '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=acheve',
            );
        const workflowArticle = screen.getByRole('heading', { name: 'Circuit de validation' })
            .closest('article');
        expect(workflowArticle).not.toBeNull();
        expect(within(workflowArticle as HTMLElement).getByText('Cloture').closest('a'))
            .toHaveAttribute(
                'href',
                '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_suivi=cloture',
            );
        const alertArticle = screen.getByRole('heading', { name: 'Alertes d’échéance' })
            .closest('article');
        expect(alertArticle).not.toBeNull();
        expect(within(alertArticle as HTMLElement).getByText(/En retard/i).closest('a'))
            .toHaveAttribute(
                'href',
                '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&alerte_echeance=en_retard',
            );
    });

    it('falls back to the safe category link when a row link is external', () => {
        const data = dashboardFixture();
        data.links.breakdowns!.actions!.acheve = 'https://evil.example/collect';

        render(<DashboardPage result={{ ok: true, data }} />);

        const article = screen.getByRole('heading', { name: 'Avancement des actions' })
            .closest('article');
        expect(article).not.toBeNull();
        expect(within(article as HTMLElement).getByText('Acheve').closest('a'))
            .toHaveAttribute('href', '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced');
    });

    it('renders a useful empty state when all aggregate values are zero', () => {
        const data = dashboardFixture();
        data.metrics.totals = Object.fromEntries(
            Object.keys(data.metrics.totals).map((key) => [key, 0]),
        );
        data.synthesis_decision_summary.total = 0;

        render(<DashboardPage result={{ ok: true, data }} />);

        expect(screen.getByText('Aucune donnée ne correspond aux filtres sélectionnés'))
            .toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Consulter les actions' }))
            .toHaveAttribute('href', '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced');
    });

    it('keeps only the currently selected progressive accordion open', async () => {
        const user = userEvent.setup();

        render(<DashboardPage result={{ ok: true, data: dashboardFixture() }} />);

        const overview = screen.getByText('Vue d’ensemble').closest('details');
        const tracking = screen.getByText('Contrôle et suivi').closest('details');

        expect(overview).toHaveAttribute('open');
        expect(tracking).not.toHaveAttribute('open');

        await user.click(screen.getByText('Contrôle et suivi'));

        expect(overview).not.toHaveAttribute('open');
        expect(tracking).toHaveAttribute('open');
    });

    it.each([
        ['unauthenticated', 401, 'Connexion requise'],
        ['forbidden', 403, 'Accès non autorisé'],
        ['expired', 419, 'Session expirée'],
        ['validation', 422, 'Filtres non valides'],
        ['unavailable', 0, 'Service indisponible'],
    ] as const)('renders the %s response state', (kind, status, title) => {
        const result: DashboardFetchResult = {
            ok: false,
            kind,
            status,
            message: 'Message de test',
            errors: kind === 'validation' ? ['Filtre invalide'] : [],
        };

        render(<DashboardPage result={result} />);

        expect(screen.getByRole('heading', { name: title })).toBeInTheDocument();
    });
});
