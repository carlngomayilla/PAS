import type { DashboardOverviewPayload } from '@/lib/dashboard-contract';

export function dashboardFixture(): DashboardOverviewPayload {
    return {
        schema_version: '1.0',
        generated_at: '2026-08-23T10:00:00.000Z',
        scope: {
            mode: 'pilotage',
            user_role: 'dg',
            effective_role: 'dg',
            cross_organization_filters: true,
            organization_filters_enabled: true,
            read_only: false,
            direction_id: 1,
            service_id: null,
            selected_direction_id: 1,
            selected_service_id: null,
        },
        direction_selector: {
            enabled: true,
            selected_id: 1,
            selected_label: 'Direction générale',
            service_selected_id: null,
            service_selected_label: '',
            options: [
                { id: 1, label: 'Direction générale' },
                { id: 2, label: 'Direction financière' },
            ],
            service_options: [
                { id: 10, label: 'Service planification' },
            ],
        },
        filters: {
            periode: 'q1',
            periode_label: 'Premier trimestre',
            statut_action: null,
            statut_suivi: null,
            statut_delai: null,
            alerte_echeance: null,
            responsable_id: null,
        },
        filter_options: {
            years: [
                { value: '2026', label: '2026' },
                { value: '2025', label: '2025' },
            ],
            quarters: [
                { value: 'q1', label: 'T1' },
                { value: 'q2', label: 'T2' },
            ],
            periods: [
                { value: 'q1', label: 'T1' },
                { value: 'q2', label: 'T2' },
            ],
            action_statuses: [
                { value: 'en_cours', label: 'En cours' },
                { value: 'a_corriger', label: 'À corriger' },
                { value: 'acheve', label: 'Achevée' },
            ],
            tracking_statuses: [
                { value: 'en_cours', label: 'En cours' },
            ],
            delay_statuses: [
                { value: 'hors_delai', label: 'Hors délai' },
            ],
            deadline_alerts: [
                { value: 'critique', label: 'Critique' },
            ],
            responsibles: [
                { id: 5, label: 'Responsable test' },
            ],
        },
        exercise: {
            year: 2026,
            quarter: 'q1',
        },
        metrics: {
            totals: {
                pas_total: 2,
                pas_actifs: 1,
                paos_total: 4,
                paos_actifs: 3,
                ptas_total: 8,
                ptas_actifs: 5,
                actions_total: 20,
                actions_validees: 12,
                kpis_total: 9,
                kpi_mesures_total: 30,
            },
            alerts: {
                actions_en_retard: 3,
                mesures_kpi_sous_seuil: 2,
                alertes_action_actives: 4,
            },
            status_breakdown: {
                actions: {
                    en_cours: 8,
                    acheve: 12,
                },
            },
            action_scope: {
                mode: 'pilotage',
                visible_actions_total: 20,
                personal_actions_total: 0,
                dashboard_actions_total: 20,
            },
        },
        synthesis_decision_summary: {
            total: 20,
            taux_execution: 62.5,
            performance_pta: 71.2,
            workflow: {
                en_cours: 8,
                cloture: 12,
            },
            delay: {
                dans_les_delais: 17,
                hors_delai: 3,
            },
            alerts: {
                aucune_alerte: 13,
                critique: 3,
                en_retard: 4,
            },
        },
        financial_summary: {
            budget: 1000000,
            engaged: 650000,
            disbursed: 400000,
            remaining: 350000,
            engagement_rate: 65,
            disbursement_rate: 40,
            actions_total: 8,
        },
        links: {
            blade_pilotage: '/dashboard?dashboardTab=overview',
            tables: '/dashboard?dashboardTab=advanced',
            charts: '/dashboard?dashboardTab=charts',
            actions: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced',
            reporting: '/workspace/reporting',
            alerts: '/workspace/notifications',
            pta_tracking: '/workspace/pilotage',
            pas: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced',
            paos: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced',
            ptas: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced',
            late_actions: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=en_retard',
            kpi_below_threshold: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced',
            breakdowns: {
                actions: {
                    a_parametrer: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=a_parametrer',
                    non_demarre: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=non_demarre',
                    en_cours: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=en_cours',
                    a_risque: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=a_risque',
                    en_avance: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=en_avance',
                    en_retard: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=en_retard',
                    a_corriger: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=a_corriger',
                    suspendu: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=suspendu',
                    annule: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=annule',
                    acheve: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_action=acheve',
                },
                workflow: {
                    a_parametrer: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_suivi=a_parametrer',
                    non_demarre: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_suivi=non_demarre',
                    en_cours: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_suivi=en_cours',
                    validation_chef: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_suivi=validation_chef',
                    validation_controleur: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_suivi=validation_controleur',
                    validation_planification: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_suivi=validation_planification',
                    cloture: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&statut_suivi=cloture',
                },
                alerts: {
                    aucune_alerte: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&alerte_echeance=aucune_alerte',
                    echeance_proche: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&alerte_echeance=echeance_proche',
                    critique: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&alerte_echeance=critique',
                    en_retard: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&alerte_echeance=en_retard',
                    cloturee: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&alerte_echeance=cloturee',
                    a_parametrer: '/dashboard?exercice=2026&periode=q1&direction_id=1&dashboardTab=advanced&alerte_echeance=a_parametrer',
                },
            },
        },
    };
}
