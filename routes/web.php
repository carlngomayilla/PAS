<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\Web\ActionWebController;
use App\Http\Controllers\Web\ActionTrackingWebController;
use App\Http\Controllers\Web\AuditWebController;
use App\Http\Controllers\Web\DependentSelectController;
use App\Http\Controllers\Web\GlobalSearchWebController;
use App\Http\Controllers\Web\GovernanceWebController;
use App\Http\Controllers\Web\KpiMesureWebController;
use App\Http\Controllers\Web\KpiWebController;
use App\Http\Controllers\Web\MonitoringWebController;
use App\Http\Controllers\Web\NotificationWebController;
use App\Http\Controllers\Web\PaoWebController;
use App\Http\Controllers\Web\PasWebController;
use App\Http\Controllers\Web\PersonalTaskWebController;
use App\Http\Controllers\Web\PlanningImportWebController;
use App\Http\Controllers\Web\PlanningUnlockWebController;
use App\Http\Controllers\Web\ProfileWebController;
use App\Http\Controllers\Web\PtaWebController;
use App\Http\Controllers\Web\ReferentielWebController;
use App\Http\Controllers\Web\SuperAdminWebController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsurePasswordIsFresh;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

// ── ACCUEIL ────────────────────────────────────────────────────────────────────
// Redirige la racine vers la page de connexion.
Route::get('/', function () {
    return redirect()->route('login.form');
});

// ── AUTHENTIFICATION (pages publiques) ─────────────────────────────────────────
// Accessible uniquement aux visiteurs non connectés.
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login.form');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login');

    // ── Réinitialisation du mot de passe ──────────────────────────────────────
    Route::get('/password/forgot', [\App\Http\Controllers\Auth\PasswordResetController::class, 'showRequestForm'])
        ->name('password.request');
    Route::post('/password/forgot', [\App\Http\Controllers\Auth\PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:6,1')
        ->name('password.email');
    Route::get('/password/reset/{token}', [\App\Http\Controllers\Auth\PasswordResetController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('/password/reset', [\App\Http\Controllers\Auth\PasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1')
        ->name('password.update');
});

// ── ESPACE AUTHENTIFIÉ ─────────────────────────────────────────────────────────
// Toutes les routes ci-dessous nécessitent d'être connecté et d'avoir un compte actif.
Route::middleware(['auth', EnsureActiveAccount::class])->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    // ── PROFIL UTILISATEUR ─────────────────────────────────────────────────────
    // Gestion du compte personnel : informations, mot de passe, sessions ouvertes.
    Route::get('/workspace/profil', [ProfileWebController::class, 'edit'])->name('workspace.profile.edit');
    Route::put('/workspace/profil', [ProfileWebController::class, 'update'])->name('workspace.profile.update');
    Route::post('/workspace/profil/sessions/revoke-current', [ProfileWebController::class, 'revokeCurrentSession'])
        ->name('workspace.profile.sessions.revoke_current');
    Route::post('/workspace/profil/sessions/revoke-others', [ProfileWebController::class, 'revokeOtherSessions'])
        ->name('workspace.profile.sessions.revoke_others');
    Route::post('/workspace/profil/sessions/{sessionId}/revoke', [ProfileWebController::class, 'revokeSession'])
        ->name('workspace.profile.sessions.revoke');

    Route::middleware(EnsurePasswordIsFresh::class)->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::prefix('/admin')->name('admin.')->group(function (): void {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/settings', fn () => redirect()->route('workspace.profile.edit'))->name('settings');
            Route::get('/referentiel', fn () => redirect()->route('workspace.referentiel.directions.index'))->name('referentiel');
        });

        Route::get('/actions/create', static function () {
            return redirect()
                ->route('workspace.pta.index')
                ->with('info', 'Les actions sont desormais creees depuis le PTA. Ce module est reserve au suivi, au controle et a la validation.');
        });
        Route::post('/actions', static function () {
            abort(403, 'Les actions sont desormais creees depuis le PTA. Ce module est reserve au suivi, au controle et a la validation.');
        });

        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace.index');
        Route::get('/workspace/recherche', [GlobalSearchWebController::class, 'index'])->name('workspace.search');
        Route::get('/workspace/notifications', [NotificationWebController::class, 'index'])
            ->name('workspace.notifications.index');
        Route::get('/workspace/notifications/{notification}/read', [NotificationWebController::class, 'read'])
            ->name('workspace.notifications.read');
        Route::post('/workspace/notifications/read-all', [NotificationWebController::class, 'readAll'])
            ->name('workspace.notifications.read_all');
        Route::get('/workspace/mes-taches', [PersonalTaskWebController::class, 'index'])
            ->name('workspace.tasks.index');

        // ── RÉFÉRENTIEL (Directions, Services, Utilisateurs) ──────────────────────
        // Gestion des structures organisationnelles accessibles aux administrateurs.
        Route::get('/workspace/referentiel/directions', [ReferentielWebController::class, 'directionsIndex'])
            ->name('workspace.referentiel.directions.index');
        Route::get('/workspace/referentiel/directions/create', [ReferentielWebController::class, 'directionsCreate'])
            ->name('workspace.referentiel.directions.create');
        Route::post('/workspace/referentiel/directions', [ReferentielWebController::class, 'directionsStore'])
            ->name('workspace.referentiel.directions.store');
        Route::get('/workspace/referentiel/directions/{direction}/edit', [ReferentielWebController::class, 'directionsEdit'])
            ->name('workspace.referentiel.directions.edit');
        Route::put('/workspace/referentiel/directions/{direction}', [ReferentielWebController::class, 'directionsUpdate'])
            ->name('workspace.referentiel.directions.update');
        Route::delete('/workspace/referentiel/directions/{direction}', [ReferentielWebController::class, 'directionsDestroy'])
            ->name('workspace.referentiel.directions.destroy');

        Route::get('/workspace/referentiel/services', [ReferentielWebController::class, 'servicesIndex'])
            ->name('workspace.referentiel.services.index');
        Route::get('/workspace/referentiel/services/create', [ReferentielWebController::class, 'servicesCreate'])
            ->name('workspace.referentiel.services.create');
        Route::post('/workspace/referentiel/services', [ReferentielWebController::class, 'servicesStore'])
            ->name('workspace.referentiel.services.store');
        Route::get('/workspace/referentiel/services/{service}/edit', [ReferentielWebController::class, 'servicesEdit'])
            ->name('workspace.referentiel.services.edit');
        Route::put('/workspace/referentiel/services/{service}', [ReferentielWebController::class, 'servicesUpdate'])
            ->name('workspace.referentiel.services.update');
        Route::delete('/workspace/referentiel/services/{service}', [ReferentielWebController::class, 'servicesDestroy'])
            ->name('workspace.referentiel.services.destroy');

        Route::get('/workspace/referentiel/utilisateurs', [ReferentielWebController::class, 'utilisateursIndex'])
            ->name('workspace.referentiel.utilisateurs.index');
        Route::get('/workspace/referentiel/utilisateurs/create', [ReferentielWebController::class, 'utilisateursCreate'])
            ->name('workspace.referentiel.utilisateurs.create');
        Route::post('/workspace/referentiel/utilisateurs', [ReferentielWebController::class, 'utilisateursStore'])
            ->name('workspace.referentiel.utilisateurs.store');
        Route::get('/workspace/referentiel/utilisateurs/{utilisateur}/edit', [ReferentielWebController::class, 'utilisateursEdit'])
            ->name('workspace.referentiel.utilisateurs.edit');
        Route::put('/workspace/referentiel/utilisateurs/{utilisateur}', [ReferentielWebController::class, 'utilisateursUpdate'])
            ->name('workspace.referentiel.utilisateurs.update');
        Route::post('/workspace/referentiel/utilisateurs/{utilisateur}/demande-suppression', [ReferentielWebController::class, 'utilisateursDeletionRequestStore'])
            ->name('workspace.referentiel.utilisateurs.deletion-requests.store');
        Route::delete('/workspace/referentiel/utilisateurs/{utilisateur}', [ReferentielWebController::class, 'utilisateursDestroy'])
            ->name('workspace.referentiel.utilisateurs.destroy');

        // ── GOUVERNANCE (Documentation API, Rétention, Délégations) ──────────────
        // Analyse canonique du modele PAS (kit IDE/dark, vue statique).
        Route::get('/workspace/analyses/model', fn () => view('analyses.model'))
            ->name('workspace.analyses.model');

        Route::get('/workspace/documentation-api', [GovernanceWebController::class, 'apiDocumentation'])
            ->name('workspace.api-docs.index');
        Route::get('/workspace/documentation-api/openapi.yaml', [GovernanceWebController::class, 'apiSpec'])
            ->name('workspace.api-docs.spec');
        Route::get('/workspace/retention', [GovernanceWebController::class, 'retentionIndex'])
            ->name('workspace.retention.index');
        Route::post('/workspace/retention/run', [GovernanceWebController::class, 'retentionRun'])
            ->name('workspace.retention.run');
        Route::get('/workspace/referentiel/delegations', [GovernanceWebController::class, 'delegationsIndex'])
            ->name('workspace.delegations.index');
        Route::get('/workspace/referentiel/delegations/create', [GovernanceWebController::class, 'delegationsCreate'])
            ->name('workspace.delegations.create');
        Route::post('/workspace/referentiel/delegations', [GovernanceWebController::class, 'delegationsStore'])
            ->name('workspace.delegations.store');
        Route::post('/workspace/referentiel/delegations/{delegation}/cancel', [GovernanceWebController::class, 'delegationsCancel'])
            ->name('workspace.delegations.cancel');

        // ── WORKSPACE PRINCIPAL ────────────────────────────────────────────────────
        // Contient tous les modules métier : PAS, PAO, PTA, Actions, KPI, Messagerie...
        Route::prefix('/workspace')->name('workspace.')->group(function (): void {

            // Menus déroulants dynamiques (chargés en AJAX lors du remplissage des formulaires)
            Route::get('ajax/services', [DependentSelectController::class, 'services'])->name('ajax.services');
            Route::get('ajax/users', [DependentSelectController::class, 'users'])->name('ajax.users');
            Route::get('ajax/objectifs-operationnels', [DependentSelectController::class, 'objectifsOperationnels'])->name('ajax.objectifs-operationnels');
            Route::get('ajax/ptas', [DependentSelectController::class, 'ptas'])->name('ajax.ptas');
            Route::get('ajax/actions', [DependentSelectController::class, 'actions'])->name('ajax.actions');

            Route::get('imports-excel', [PlanningImportWebController::class, 'index'])->name('imports.index');
            Route::get('imports-excel/nouveau', [PlanningImportWebController::class, 'create'])->name('imports.create');
            Route::post('imports-excel/preview', [PlanningImportWebController::class, 'preview'])->name('imports.preview');
            Route::get('imports-excel/modele', [PlanningImportWebController::class, 'template'])->name('imports.template');
            Route::get('imports-excel/{import}', [PlanningImportWebController::class, 'show'])->name('imports.show');
            Route::post('imports-excel/{import}/colonnes', [PlanningImportWebController::class, 'mapping'])->name('imports.mapping');
            Route::post('imports-excel/{import}/confirmer', [PlanningImportWebController::class, 'confirm'])->name('imports.confirm');
            Route::get('imports-excel/{import}/resultat', [PlanningImportWebController::class, 'result'])->name('imports.result');
            Route::get('imports-excel/{import}/erreurs', [PlanningImportWebController::class, 'errors'])->name('imports.errors');
            Route::get('imports-excel/{import}/rapport-erreurs', [PlanningImportWebController::class, 'errorReport'])->name('imports.error-report');

            Route::get('demandes-deverrouillage', [PlanningUnlockWebController::class, 'index'])
                ->name('planning-unlocks.index');

            // ── PAS — Plan d'Actions Stratégique ──────────────────────────────────
            // Document pluriannuel institutionnel : axes et objectifs stratégiques.
            // Note (2026-05-29) : routes legacy (submit/approve/lock/reopen,
            // pas-axes.legacy, pas-objectifs.legacy) SUPPRIMEES — n'etaient que
            // des stubs no-op retournant une erreur. Le cycle reel est actif →
            // cloture → archive via cloturer/archiver.
            Route::resource('pas', PasWebController::class)
                ->except(['show'])
                ->parameters(['pas' => 'pas']);
            Route::post('pas/{pas}/cloturer', [PasWebController::class, 'close'])->name('pas.close');
            Route::post('pas/{pas}/archiver', [PasWebController::class, 'archive'])->name('pas.archive');
            Route::post('pas/{pas}/demandes-deverrouillage', [PlanningUnlockWebController::class, 'storePas'])
                ->name('pas.unlock-requests.store');

            // ── PAO — Plan d'Actions Opérationnel ─────────────────────────────────
            // Déclinaison annuelle du PAS par direction, avec objectifs opérationnels.
            // Routes legacy (submit/approve/lock/reopen) SUPPRIMEES (2026-05-29).
            Route::resource('pao', PaoWebController::class)
                ->except(['show'])
                ->parameters(['pao' => 'pao']);
            Route::post('pao/{pao}/cloturer', [PaoWebController::class, 'close'])->name('pao.close');
            Route::post('pao/{pao}/archiver', [PaoWebController::class, 'archive'])->name('pao.archive');

            // ── PTA — Plan de Travail Annuel ───────────────────────────────────────
            // Organisation des actions d'un service pour l'année, rattaché à un PAO.
            // Routes legacy (submit/approve/lock/reopen) SUPPRIMEES (2026-05-29).
            Route::resource('pta', PtaWebController::class)
                ->except(['show'])
                ->parameters(['pta' => 'pta']);
            Route::post('pta/{pta}/cloturer', [PtaWebController::class, 'close'])->name('pta.close');
            Route::post('pta/{pta}/archiver', [PtaWebController::class, 'archive'])->name('pta.archive');
            Route::post('pta/{pta}/demandes-deverrouillage', [PlanningUnlockWebController::class, 'storePta'])
                ->name('pta.unlock-requests.store');

            // Sauvegarde inline d'une seule action depuis le formulaire PTA (AJAX).
            // Recoit le payload d'une action structuree comme dans le formulaire global
            // (mais pour une seule action). Cree si pas d'id, met a jour sinon.
            Route::post('pta/{pta}/actions/upsert-inline', [PtaWebController::class, 'upsertActionInline'])
                ->name('pta.actions.upsert-inline');

            // ── ACTIONS ────────────────────────────────────────────────────────────
            // Tâches concrètes rattachées à un PTA : suivi, validation, clôture, KPI.
            Route::get('daf/financements-actions', [ActionWebController::class, 'financingRequests'])
                ->name('daf.financements.index');

            Route::patch('actions/{action}/quick-statut', [ActionWebController::class, 'quickStatus'])
                ->name('actions.quick-status');
            Route::post('actions/{action}/demandes-deverrouillage', [PlanningUnlockWebController::class, 'storeAction'])
                ->name('actions.unlock-requests.store');
            // Circuit V2 : directeur transfère → planification donne avis → DG décide.
            Route::post('demandes-deverrouillage/{planningUnlockRequest}/transfert', [PlanningUnlockWebController::class, 'transferByDirecteur'])
                ->name('planning-unlocks.transfer');
            Route::post('demandes-deverrouillage/{planningUnlockRequest}/avis-planif', [PlanningUnlockWebController::class, 'reviewByPlanification'])
                ->name('planning-unlocks.planif');
            Route::post('demandes-deverrouillage/{planningUnlockRequest}/dg', [PlanningUnlockWebController::class, 'reviewByDg'])
                ->name('planning-unlocks.dg');

            Route::get('actions/create', static function () {
                return redirect()
                    ->route('workspace.pta.index')
                    ->with('info', 'Les actions sont desormais creees depuis le PTA. Ce module est reserve au suivi, au controle et a la validation.');
            })->name('actions.create');
            Route::post('actions', static function () {
                abort(403, 'Les actions sont desormais creees depuis le PTA. Ce module est reserve au suivi, au controle et a la validation.');
            })->name('actions.store');
            Route::resource('actions', ActionWebController::class)
                ->except(['show', 'create', 'store'])
                ->parameters(['actions' => 'action']);
            Route::get('kpi', fn () => redirect()
                ->route('workspace.actions.index')
                ->with('info', 'Le parametrage des indicateurs se fait maintenant directement dans les actions.'))
                ->name('kpi.index');
            Route::get('kpi/create', fn () => redirect()
                ->route('workspace.pta.index')
                ->with('info', 'Les indicateurs attendus sont desormais definis lors de la creation des actions dans le PTA.'))
                ->name('kpi.create');
            Route::post('kpi', [KpiWebController::class, 'store'])
                ->name('kpi.store');
            Route::get('kpi/{kpi}/edit', function (Kpi $kpi) {
                $target = $kpi->action_id !== null
                    ? route('workspace.actions.edit', $kpi->action_id).'#action-indicator-settings'
                    : route('workspace.actions.index');

                return redirect()
                    ->to($target)
                    ->with('info', 'Le parametrage des indicateurs se fait maintenant directement dans les actions.');
            })->name('kpi.edit');
            Route::put('kpi/{kpi}', [KpiWebController::class, 'update'])
                ->name('kpi.update');
            Route::delete('kpi/{kpi}', [KpiWebController::class, 'destroy'])
                ->name('kpi.destroy');
            Route::get('kpi-mesures', fn () => redirect()
                ->route('workspace.actions.index')
                ->with('info', 'Le suivi des indicateurs passe desormais par les actions et le reporting.'))
                ->name('kpi-mesures.index');
            Route::get('kpi-mesures/create', fn () => redirect()
                ->route('workspace.actions.index')
                ->with('info', 'Le suivi des indicateurs passe desormais par les actions et le reporting.'))
                ->name('kpi-mesures.create');
            Route::post('kpi-mesures', [KpiMesureWebController::class, 'store'])
                ->name('kpi-mesures.store');
            Route::get('kpi-mesures/{kpiMesure}/edit', function (KpiMesure $kpiMesure) {
                $actionId = $kpiMesure->kpi?->action_id;
                $target = $actionId !== null
                    ? route('workspace.actions.suivi', $actionId)
                    : route('workspace.actions.index');

                return redirect()
                    ->to($target)
                    ->with('info', 'Le suivi des indicateurs passe desormais par les actions et le reporting.');
            })->name('kpi-mesures.edit');
            Route::put('kpi-mesures/{kpiMesure}', [KpiMesureWebController::class, 'update'])
                ->name('kpi-mesures.update');
            Route::delete('kpi-mesures/{kpiMesure}', [KpiMesureWebController::class, 'destroy'])
                ->name('kpi-mesures.destroy');
            // ── PAGE DE SUIVI + WORKFLOW V2 (cf. docs/WORKFLOW-SUIVI-V2.md) ─────
            Route::get('actions/{action}/suivi', [ActionTrackingWebController::class, 'show'])
                ->name('actions.suivi');
            // Suivi opérationnel V2 : enregistrement (save) + soumission (submit).
            Route::post('actions/{action}/execution', [ActionTrackingWebController::class, 'updateActionProgress'])
                ->name('actions.execution.update');
            Route::post('actions/{action}/sous-actions/{sousAction}', [ActionTrackingWebController::class, 'updateSubActionProgress'])
                ->name('actions.sub-actions.update');
            // Validation chef (action simple ou sous-action via sous_action_id).
            Route::post('actions/{action}/review', [ActionTrackingWebController::class, 'reviewItem'])
                ->name('actions.review');
            Route::post('actions/{action}/financement/daf', [ActionTrackingWebController::class, 'reviewFinancingByDaf'])
                ->name('actions.financement.daf');
            Route::post('actions/{action}/financement/daf/statut', [ActionTrackingWebController::class, 'updateFinancingStatusByDaf'])
                ->name('actions.financement.daf.status');
            Route::post('actions/{action}/financement/dg', [ActionTrackingWebController::class, 'reviewFinancingByDg'])
                ->name('actions.financement.dg');
            Route::post('actions/{action}/commentaires', [ActionTrackingWebController::class, 'comment'])
                ->name('actions.comment');
            Route::get('actions/{action}/justificatifs/{justificatif}/download', [ActionTrackingWebController::class, 'downloadJustificatif'])
                ->name('actions.justificatifs.download');
            Route::get('actions/{action}/justificatifs/{justificatif}/preview', [ActionTrackingWebController::class, 'previewJustificatif'])
                ->name('actions.justificatifs.preview');
            // Routes legacy NON ré-implémentées en V2 (anomalies, reports d'échéance,
            // validation direction, suivi hebdo) → 410 Gone tant que non rouvertes.
            Route::any('actions/{action}/sous-actions/{sousAction}/review', static function () {
                abort(410, 'Validation par route dédiée supprimée : utilisez actions.review avec sous_action_id.');
            })->name('actions.sub-actions.review');
            Route::any('actions/{action}/review-direction', static function () {
                abort(410, 'Workflow de validation direction supprime (refonte en cours).');
            })->name('actions.review-direction');
            Route::any('actions/{action}/review-direction', static function () {
                abort(410, 'Workflow de validation direction supprime (refonte en cours).');
            })->name('actions.review-direction');
            Route::any('actions/{action}/anomalies', static function () {
                abort(410, 'Workflow de gestion d\'anomalies supprime (refonte en cours).');
            })->name('actions.anomalies.signal');
            Route::any('actions/{action}/anomalies/{log}/resolve', static function () {
                abort(410, 'Workflow de gestion d\'anomalies supprime (refonte en cours).');
            })->name('actions.anomalies.resolve');
            Route::any('actions/{action}/reports-echeance', static function () {
                abort(410, 'Workflow de report d\'echeance supprime (refonte en cours).');
            })->name('actions.deadline-extension.store');
            Route::any('reports-echeance/{deadlineExtensionRequest}/sciq', static function () {
                abort(410, 'Workflow de report d\'echeance SCIQ supprime (refonte en cours).');
            })->name('deadline-extension.sciq');
            Route::any('reports-echeance/{deadlineExtensionRequest}/dg', static function () {
                abort(410, 'Workflow de report d\'echeance DG supprime (refonte en cours).');
            })->name('deadline-extension.dg');
            Route::any('actions/{action}/semaines/{week}/soumettre', static function () {
                abort(410, 'Le suivi hebdomadaire a ete supprime du circuit metier.');
            })->name('actions.weeks.submit');

        });

        // ── REPORTING & EXPORTS ────────────────────────────────────────────────────
        // Synthèses de performance, tableaux de bord et exports (Excel, PDF).
        Route::get('/workspace/reporting', [MonitoringWebController::class, 'reporting'])
            ->name('workspace.reporting');
        Route::get('/workspace/reporting/export/excel', [MonitoringWebController::class, 'exportExcel'])
            ->name('workspace.reporting.export.excel');
        Route::get('/workspace/reporting/export/pdf', [MonitoringWebController::class, 'exportPdf'])
            ->name('workspace.reporting.export.pdf');
        Route::post('/workspace/reporting/export/{format}/queue', [MonitoringWebController::class, 'queueExport'])
            ->whereIn('format', ['excel', 'pdf'])
            ->name('workspace.reporting.export.queue');
        Route::get('/workspace/reporting/export-ready', [MonitoringWebController::class, 'downloadQueuedExport'])
            // A29 — La route est `signed` (anti-tampering) ET throttled
            // (`api-downloads`, 30 req/min) pour bloquer le scraping massif
            // d exports meme avec une URL signed valide.
            ->middleware(['signed', 'throttle:api-downloads'])
            ->name('workspace.reporting.exports.download');
        Route::redirect('/workspace/pilotage', '/dashboard');
        Route::get('/workspace/alertes', [MonitoringWebController::class, 'alertes'])
            ->name('workspace.alertes');
        Route::get('/workspace/alertes/dropdown', [MonitoringWebController::class, 'alertesDropdown'])
            ->name('workspace.alertes.dropdown');
        Route::post('/workspace/alertes/read-all', [MonitoringWebController::class, 'readAllAlertes'])
            ->name('workspace.alertes.read_all');
        Route::get('/workspace/alertes/{type}/{id}/read', [MonitoringWebController::class, 'readAlerte'])
            ->whereNumber('id')
            ->name('workspace.alertes.read');
        Route::get('/workspace/{module}', [WorkspaceController::class, 'module'])
            ->whereIn('module', [
                'corrections',
                'agents',
                'controle',
                'synthese-agence',
                'arbitrages',
                'financements-critiques',
                'rapports-consolides',
                'supervision',
            ])
            ->name('workspace.module');

        // ── JOURNAL D'AUDIT ────────────────────────────────────────────────────────
        // Historique de toutes les actions sensibles réalisées dans l'application.
        Route::get('/workspace/audit', [AuditWebController::class, 'index'])
            ->name('workspace.audit.index');

        // ── SUPER ADMINISTRATION ───────────────────────────────────────────────────
        // Configuration avancée réservée aux super-admins : paramètres système,
        // workflow, apparence, organisation, templates d'export, simulation...
        Route::prefix('/workspace/super-admin')->name('workspace.super-admin.')->group(function (): void {
            Route::get('/', [SuperAdminWebController::class, 'index'])->name('index');
            Route::get('/parametres-generaux', [SuperAdminWebController::class, 'settingsEdit'])->name('settings.edit');
            Route::post('/parametres-generaux/brouillon', [SuperAdminWebController::class, 'settingsDraftUpdate'])->name('settings.draft');
            Route::post('/parametres-generaux/publier-brouillon', [SuperAdminWebController::class, 'settingsPublishDraft'])->name('settings.publish-draft');
            Route::post('/parametres-generaux/supprimer-brouillon', [SuperAdminWebController::class, 'settingsDiscardDraft'])->name('settings.discard-draft');
            Route::put('/parametres-generaux', [SuperAdminWebController::class, 'settingsUpdate'])->name('settings.update');
            Route::get('/modules-navigation', [SuperAdminWebController::class, 'modulesEdit'])->name('modules.edit');
            Route::post('/modules-navigation/brouillon', [SuperAdminWebController::class, 'modulesDraftUpdate'])->name('modules.draft');
            Route::post('/modules-navigation/publier-brouillon', [SuperAdminWebController::class, 'modulesPublishDraft'])->name('modules.publish-draft');
            Route::post('/modules-navigation/supprimer-brouillon', [SuperAdminWebController::class, 'modulesDiscardDraft'])->name('modules.discard-draft');
            Route::put('/modules-navigation', [SuperAdminWebController::class, 'modulesUpdate'])->name('modules.update');
            Route::get('/roles-permissions', [SuperAdminWebController::class, 'rolesEdit'])->name('roles.edit');
            Route::put('/roles-permissions', [SuperAdminWebController::class, 'rolesUpdate'])->name('roles.update');
            Route::put('/roles-permissions/registry', [SuperAdminWebController::class, 'rolesRegistryUpdate'])->name('roles.registry.update');
            Route::post('/roles-permissions/registry/duplicate', [SuperAdminWebController::class, 'rolesRegistryDuplicate'])->name('roles.registry.duplicate');
            Route::post('/roles-permissions/registry/restore/{versionId}', [SuperAdminWebController::class, 'rolesRegistryRestore'])->name('roles.registry.restore');
            // Unités DG (SCIQ, DGA, Cabinet, UCAS) — pilotage des chefs d'unité
            Route::get('/unites-dg', [SuperAdminWebController::class, 'unitesDgIndex'])->name('unites-dg.index');
            Route::put('/unites-dg/{uniteDg}/chef', [SuperAdminWebController::class, 'unitesDgSetChef'])->name('unites-dg.set-chef');

            Route::get('/organisation-utilisateurs', [SuperAdminWebController::class, 'organizationIndex'])->name('organization.index');
            Route::post('/organisation-utilisateurs/directions', [SuperAdminWebController::class, 'organizationDirectionStore'])->name('organization.directions.store');
            Route::put('/organisation-utilisateurs/directions/{direction}', [SuperAdminWebController::class, 'organizationDirectionUpdate'])->name('organization.directions.update');
            Route::post('/organisation-utilisateurs/directions/{direction}/toggle', [SuperAdminWebController::class, 'organizationDirectionToggle'])->name('organization.directions.toggle');
            Route::post('/organisation-utilisateurs/services', [SuperAdminWebController::class, 'organizationServiceStore'])->name('organization.services.store');
            Route::put('/organisation-utilisateurs/services/{service}', [SuperAdminWebController::class, 'organizationServiceUpdate'])->name('organization.services.update');
            Route::post('/organisation-utilisateurs/services/{service}/toggle', [SuperAdminWebController::class, 'organizationServiceToggle'])->name('organization.services.toggle');
            Route::post('/organisation-utilisateurs/utilisateurs', [SuperAdminWebController::class, 'organizationUserStore'])->name('organization.users.store');
            Route::put('/organisation-utilisateurs/utilisateurs/{managedUser}', [SuperAdminWebController::class, 'organizationUserUpdate'])->name('organization.users.update');
            Route::post('/organisation-utilisateurs/utilisateurs/{managedUser}/toggle', [SuperAdminWebController::class, 'organizationUserToggle'])->name('organization.users.toggle');
            Route::post('/organisation-utilisateurs/utilisateurs/{managedUser}/reset-password', [SuperAdminWebController::class, 'organizationUserResetPassword'])->name('organization.users.reset-password');
            Route::post('/organisation-utilisateurs/utilisateurs/{managedUser}/revoke-sessions', [SuperAdminWebController::class, 'organizationUserRevokeSessions'])->name('organization.users.revoke-sessions');
            Route::post('/organisation-utilisateurs/utilisateurs/import', [SuperAdminWebController::class, 'organizationUsersImport'])->name('organization.users.import');
            Route::post('/organisation-utilisateurs/utilisateurs/bulk', [SuperAdminWebController::class, 'organizationUsersBulk'])->name('organization.users.bulk');
            Route::post('/organisation-utilisateurs/demandes-suppression/{deletionRequest}/decision', [SuperAdminWebController::class, 'organizationDeletionRequestDecision'])->name('organization.deletion-requests.decision');
            Route::get('/exercices-periodes', [SuperAdminWebController::class, 'exercisesIndex'])->name('exercises.index');
            Route::post('/exercices-periodes', [SuperAdminWebController::class, 'exercisesStore'])->name('exercises.store');
            Route::put('/exercices-periodes/archivage-automatique', [SuperAdminWebController::class, 'exercisesArchiveSettingsUpdate'])->name('exercises.archive-settings.update');
            Route::put('/exercices-periodes/{exercice}', [SuperAdminWebController::class, 'exercisesUpdate'])->name('exercises.update');
            Route::post('/exercices-periodes/{exercice}/activer', [SuperAdminWebController::class, 'exercisesActivate'])->name('exercises.activate');
            Route::get('/dashboards-profils', [SuperAdminWebController::class, 'dashboardProfilesEdit'])->name('dashboard-profiles.edit');
            Route::put('/dashboards-profils', [SuperAdminWebController::class, 'dashboardProfilesUpdate'])->name('dashboard-profiles.update');
            Route::get('/referentiels-dynamiques', [SuperAdminWebController::class, 'referentialsEdit'])->name('referentials.edit');
            Route::put('/referentiels-dynamiques', [SuperAdminWebController::class, 'referentialsUpdate'])->name('referentials.update');
            Route::get('/documents-justificatifs', [SuperAdminWebController::class, 'documentsEdit'])->name('documents.edit');
            Route::put('/documents-justificatifs', [SuperAdminWebController::class, 'documentsUpdate'])->name('documents.update');
            Route::get('/kpis-pilotes', [SuperAdminWebController::class, 'kpisEdit'])->name('kpis.edit');
            Route::put('/kpis-pilotes', [SuperAdminWebController::class, 'kpisUpdate'])->name('kpis.update');
            Route::get('/apparence', [SuperAdminWebController::class, 'appearanceEdit'])->name('appearance.edit');
            Route::post('/apparence/apercu', [SuperAdminWebController::class, 'appearancePreview'])->name('appearance.preview');
            Route::post('/apparence/brouillon', [SuperAdminWebController::class, 'appearanceDraftUpdate'])->name('appearance.draft');
            Route::post('/apparence/publier-brouillon', [SuperAdminWebController::class, 'appearancePublishDraft'])->name('appearance.publish-draft');
            Route::post('/apparence/supprimer-brouillon', [SuperAdminWebController::class, 'appearanceDiscardDraft'])->name('appearance.discard-draft');
            Route::put('/apparence', [SuperAdminWebController::class, 'appearanceUpdate'])->name('appearance.update');
            Route::get('/workflow-validations', [SuperAdminWebController::class, 'workflowEdit'])->name('workflow.edit');
            Route::put('/workflow-validations', [SuperAdminWebController::class, 'workflowUpdate'])->name('workflow.update');
            Route::get('/politique-calcul-actions', [SuperAdminWebController::class, 'calculationEdit'])->name('calculation.edit');
            Route::put('/politique-calcul-actions', [SuperAdminWebController::class, 'calculationUpdate'])->name('calculation.update');
            Route::get('/alertes-notifications', [SuperAdminWebController::class, 'notificationsEdit'])->name('notifications.edit');
            Route::put('/alertes-notifications', [SuperAdminWebController::class, 'notificationsUpdate'])->name('notifications.update');
            Route::get('/parametres-metier-actions', [SuperAdminWebController::class, 'actionPoliciesEdit'])->name('action-policies.edit');
            Route::put('/parametres-metier-actions', [SuperAdminWebController::class, 'actionPoliciesUpdate'])->name('action-policies.update');
            Route::get('/snapshots-configuration', [SuperAdminWebController::class, 'snapshotsIndex'])->name('snapshots.index');
            Route::post('/snapshots-configuration', [SuperAdminWebController::class, 'snapshotsStore'])->name('snapshots.store');
            Route::post('/snapshots-configuration/{snapshot}/restore', [SuperAdminWebController::class, 'snapshotsRestore'])->name('snapshots.restore');
            Route::get('/simulation', [SuperAdminWebController::class, 'simulationIndex'])->name('simulation.index');
            Route::post('/simulation', [SuperAdminWebController::class, 'simulationRun'])->name('simulation.run');
            Route::get('/audit-diagnostic', [SuperAdminWebController::class, 'auditDiagnosticIndex'])->name('audit-diagnostic.index');
            Route::get('/maintenance', [SuperAdminWebController::class, 'maintenanceIndex'])->name('maintenance.index');
            Route::post('/maintenance/{action}', [SuperAdminWebController::class, 'maintenanceRun'])->name('maintenance.run');
            Route::get('/templates-export', [SuperAdminWebController::class, 'templatesIndex'])->name('templates.index');
            Route::get('/templates-export/create', [SuperAdminWebController::class, 'templatesCreate'])->name('templates.create');
            Route::post('/templates-export', [SuperAdminWebController::class, 'templatesStore'])->name('templates.store');
            Route::get('/templates-export/{template}', [SuperAdminWebController::class, 'templatesShow'])->name('templates.show');
            Route::get('/templates-export/{template}/edit', [SuperAdminWebController::class, 'templatesEdit'])->name('templates.edit');
            Route::put('/templates-export/{template}', [SuperAdminWebController::class, 'templatesUpdate'])->name('templates.update');
            Route::post('/templates-export/{template}/publish', [SuperAdminWebController::class, 'templatesPublish'])->name('templates.publish');
            Route::post('/templates-export/{template}/archive', [SuperAdminWebController::class, 'templatesArchive'])->name('templates.archive');
            Route::post('/templates-export/{template}/duplicate', [SuperAdminWebController::class, 'templatesDuplicate'])->name('templates.duplicate');
            Route::get('/templates-export/{template}/preview', [SuperAdminWebController::class, 'templatesPreview'])->name('templates.preview');
            Route::get('/templates-export/{template}/json', [SuperAdminWebController::class, 'templatesExportJson'])->name('templates.export-json');
            Route::post('/templates-export/import-json', [SuperAdminWebController::class, 'templatesImportJson'])->name('templates.import-json');
            Route::post('/templates-export/{template}/versions/{version}/restore', [SuperAdminWebController::class, 'templateVersionRestore'])->name('templates.versions.restore');
            Route::post('/templates-export/{template}/assignments', [SuperAdminWebController::class, 'assignmentStore'])->name('templates.assignments.store');
            Route::post('/templates-export/assignments/{assignment}/toggle', [SuperAdminWebController::class, 'assignmentToggle'])->name('templates.assignments.toggle');
        });
    });
});
