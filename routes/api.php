<?php

use App\Http\Controllers\Api\ActionController;
use App\Http\Controllers\Api\ActionCommentController;
use App\Http\Controllers\Api\ActionValidationController;
use App\Http\Controllers\Api\ActionWeekController;
use App\Http\Controllers\Api\AlerteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\JournalAuditController;
use App\Http\Controllers\Api\KpiController;
use App\Http\Controllers\Api\KpiMesureController;
use App\Http\Controllers\Api\PaoController;
use App\Http\Controllers\Api\PasAxeController;
use App\Http\Controllers\Api\PasController;
use App\Http\Controllers\Api\PasObjectifController;
use App\Http\Controllers\Api\PtaController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsurePasswordIsFresh;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('api.login');

    // A29 — Tout endpoint API authentifie hors `login` est limite par defaut a
    // 120 req/min/utilisateur via le rate limiter `api` (cf. AppServiceProvider).
    Route::middleware(['auth:sanctum', EnsureActiveAccount::class, EnsurePasswordIsFresh::class, 'throttle:api'])->group(function (): void {
        Route::get('me', [AuthController::class, 'me'])->name('api.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('api.logout');

        Route::get('referentiel/directions', [ReferentielController::class, 'directions'])
            ->name('api.referentiel.directions');
        Route::get('referentiel/services', [ReferentielController::class, 'services'])
            ->name('api.referentiel.services');
        Route::get('referentiel/utilisateurs', [ReferentielController::class, 'utilisateurs'])
            ->name('api.referentiel.utilisateurs');

        Route::apiResource('pas', PasController::class)
            ->parameters(['pas' => 'pas']);
        Route::apiResource('pas-axes', PasAxeController::class)
            ->parameters(['pas-axes' => 'pasAxe']);
        Route::apiResource('pas-objectifs', PasObjectifController::class)
            ->parameters(['pas-objectifs' => 'pasObjectif']);

        Route::apiResource('paos', PaoController::class)
            ->parameters(['paos' => 'pao']);

        Route::apiResource('ptas', PtaController::class)
            ->parameters(['ptas' => 'pta']);
        Route::apiResource('kpis', KpiController::class)
            ->parameters(['kpis' => 'kpi']);
        Route::apiResource('kpi-mesures', KpiMesureController::class)
            ->parameters(['kpi-mesures' => 'kpiMesure']);

        Route::post('actions', static function () {
            abort(403, 'Les actions sont desormais creees depuis le PTA. Le module Actions de l API est reserve au suivi, au controle et a la validation.');
        })->name('actions.store');
        Route::apiResource('actions', ActionController::class)
            ->except(['store'])
            ->parameters(['actions' => 'action']);
        Route::get('actions/{action}/weeks', [ActionWeekController::class, 'weeks'])
            ->name('actions.weeks');
        Route::post('actions/{action}/weeks/{actionWeek}/submit', [ActionWeekController::class, 'submitWeek'])
            ->name('actions.weeks.submit');
        Route::post('actions/{action}/review', [ActionValidationController::class, 'review'])
            ->name('actions.review');
        // Etape « validation direction » supprimee — stub 403 pour les anciens
        // consommateurs API. Cf. routes/web.php.
        Route::post('actions/{action}/review-direction', static function () {
            return response()->json([
                'message' => "L'etape de validation direction a ete supprimee. Le circuit se termine au chef de service.",
            ], 403);
        })->name('actions.review-direction');
        Route::post('actions/{action}/comments', [ActionCommentController::class, 'comment'])
            ->name('actions.comments');
        Route::get('actions/{action}/logs', [ActionCommentController::class, 'logs'])
            ->name('actions.logs');

        Route::get('journal-audit', [JournalAuditController::class, 'index'])
            ->name('journal-audit.index');
        Route::get('journal-audit/{journalAudit}', [JournalAuditController::class, 'show'])
            ->name('journal-audit.show');

        Route::get('reporting/overview', [ReportingController::class, 'overview'])
            ->name('reporting.overview');
        Route::get('alertes', [AlerteController::class, 'index'])
            ->name('alertes.index');
        Route::post('alertes/read-all', [AlerteController::class, 'readAll'])
            ->name('api.alertes.read_all');
        Route::post('alertes/{type}/{id}/read', [AlerteController::class, 'read'])
            ->whereNumber('id')
            ->name('api.alertes.read');
    });
});
