<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\Web\ActionWebController;
use App\Http\Controllers\Web\ActionTrackingWebController;
use App\Http\Controllers\Web\AuditWebController;
use App\Http\Controllers\Web\GovernanceWebController;
use App\Http\Controllers\Web\MonitoringWebController;
use App\Http\Controllers\Web\MessagingWebController;
use App\Http\Controllers\Web\NotificationWebController;
use App\Http\Controllers\Web\PaoWebController;
use App\Http\Controllers\Web\PasAxeWebController;
use App\Http\Controllers\Web\PasObjectifWebController;
use App\Http\Controllers\Web\PasWebController;
use App\Http\Controllers\Web\ProfileWebController;
use App\Http\Controllers\Web\PtaWebController;
use App\Http\Controllers\Web\ReferentielWebController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsurePasswordIsFresh;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login.form');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login.form');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login');
});

Route::middleware(['auth', EnsureActiveAccount::class])->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

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

        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace.index');
        Route::get('/workspace/messagerie', [MessagingWebController::class, 'index'])
            ->name('workspace.messaging.index');
        Route::get('/workspace/messagerie/profil/{target}/card', [MessagingWebController::class, 'profileCard'])
            ->name('workspace.messaging.profile.card');
        Route::post('/workspace/messagerie/direct/{target}', [MessagingWebController::class, 'startDirect'])
            ->name('workspace.messaging.direct');
        Route::post('/workspace/messagerie/conversations/{conversation}/messages', [MessagingWebController::class, 'send'])
            ->name('workspace.messaging.send');
        Route::get('/workspace/messagerie/conversations/{conversation}/updates', [MessagingWebController::class, 'updates'])
            ->name('workspace.messaging.updates');
        Route::get('/workspace/messagerie/conversations/{conversation}/messages/{message}/attachment', [MessagingWebController::class, 'downloadAttachment'])
            ->name('workspace.messaging.attachment.download');
        Route::post('/workspace/messagerie/conversations/{conversation}/favorite', [MessagingWebController::class, 'toggleFavorite'])
            ->name('workspace.messaging.favorite');
        Route::get('/workspace/notifications/{notification}/read', [NotificationWebController::class, 'read'])
            ->name('workspace.notifications.read');
        Route::post('/workspace/notifications/read-all', [NotificationWebController::class, 'readAll'])
            ->name('workspace.notifications.read_all');

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
        Route::delete('/workspace/referentiel/utilisateurs/{utilisateur}', [ReferentielWebController::class, 'utilisateursDestroy'])
            ->name('workspace.referentiel.utilisateurs.destroy');

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

        Route::prefix('/workspace')->name('workspace.')->group(function (): void {
            Route::resource('pas', PasWebController::class)
                ->except(['show'])
                ->parameters(['pas' => 'pas']);
            Route::post('pas/{pas}/submit', [PasWebController::class, 'submit'])->name('pas.submit');
            Route::post('pas/{pas}/approve', [PasWebController::class, 'approve'])->name('pas.approve');
            Route::post('pas/{pas}/lock', [PasWebController::class, 'lock'])->name('pas.lock');
            Route::post('pas/{pas}/reopen', [PasWebController::class, 'reopen'])->name('pas.reopen');

            // Routes legacy conservees uniquement pour rediriger vers le wizard PAS.
            Route::resource('pas-axes', PasAxeWebController::class)
                ->except(['show'])
                ->parameters(['pas-axes' => 'pasAxe']);

            Route::resource('pas-objectifs', PasObjectifWebController::class)
                ->except(['show'])
                ->parameters(['pas-objectifs' => 'pasObjectif']);

            Route::resource('pao', PaoWebController::class)
                ->except(['show'])
                ->parameters(['pao' => 'pao']);
            Route::post('pao/{pao}/submit', [PaoWebController::class, 'submit'])->name('pao.submit');
            Route::post('pao/{pao}/approve', [PaoWebController::class, 'approve'])->name('pao.approve');
            Route::post('pao/{pao}/lock', [PaoWebController::class, 'lock'])->name('pao.lock');
            Route::post('pao/{pao}/reopen', [PaoWebController::class, 'reopen'])->name('pao.reopen');

            Route::resource('pta', PtaWebController::class)
                ->except(['show'])
                ->parameters(['pta' => 'pta']);
            Route::post('pta/{pta}/submit', [PtaWebController::class, 'submit'])->name('pta.submit');
            Route::post('pta/{pta}/approve', [PtaWebController::class, 'approve'])->name('pta.approve');
            Route::post('pta/{pta}/lock', [PtaWebController::class, 'lock'])->name('pta.lock');
            Route::post('pta/{pta}/reopen', [PtaWebController::class, 'reopen'])->name('pta.reopen');

            Route::resource('actions', ActionWebController::class)
                ->except(['show'])
                ->parameters(['actions' => 'action']);
            Route::get('actions/{action}/suivi', [ActionTrackingWebController::class, 'show'])
                ->name('actions.suivi');
            Route::post('actions/{action}/semaines/{actionWeek}/soumettre', [ActionTrackingWebController::class, 'submitWeek'])
                ->name('actions.weeks.submit');
            Route::post('actions/{action}/cloturer', [ActionTrackingWebController::class, 'closeAction'])
                ->name('actions.close');
            Route::post('actions/{action}/review', [ActionTrackingWebController::class, 'reviewClosure'])
                ->name('actions.review');
            Route::post('actions/{action}/review-direction', [ActionTrackingWebController::class, 'reviewClosureByDirection'])
                ->name('actions.review-direction');
            Route::post('actions/{action}/commentaires', [ActionTrackingWebController::class, 'comment'])
                ->name('actions.comment');
            Route::get('actions/{action}/justificatifs/{justificatif}/download', [ActionTrackingWebController::class, 'downloadJustificatif'])
                ->name('actions.justificatifs.download');

        });

        Route::get('/workspace/reporting', [MonitoringWebController::class, 'reporting'])
            ->name('workspace.reporting');
        Route::get('/workspace/reporting/export/excel', [MonitoringWebController::class, 'exportExcel'])
            ->name('workspace.reporting.export.excel');
        Route::get('/workspace/reporting/export/pdf', [MonitoringWebController::class, 'exportPdf'])
            ->name('workspace.reporting.export.pdf');
        Route::get('/workspace/pilotage', [MonitoringWebController::class, 'pilotage'])
            ->name('workspace.pilotage');
        Route::get('/workspace/alertes', [MonitoringWebController::class, 'alertes'])
            ->name('workspace.alertes');
        Route::get('/workspace/alertes/dropdown', [MonitoringWebController::class, 'alertesDropdown'])
            ->name('workspace.alertes.dropdown');
        Route::post('/workspace/alertes/read-all', [MonitoringWebController::class, 'readAllAlertes'])
            ->name('workspace.alertes.read_all');
        Route::get('/workspace/alertes/{type}/{id}/read', [MonitoringWebController::class, 'readAlerte'])
            ->whereNumber('id')
            ->name('workspace.alertes.read');

        Route::get('/workspace/audit', [AuditWebController::class, 'index'])
            ->name('workspace.audit.index');
    });
});
