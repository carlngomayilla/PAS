@extends('layouts.workspace')

@section('content')
    @php
        $filters = request()->only(['q', 'direction_id', 'service_id']);
        $money = static fn (float|int|null $amount): string => number_format((float) ($amount ?? 0), 0, ',', ' ').' FCFA';
        $approvedBudget = (float) ($summary['budget'] ?? 0) + (float) ($summary['approved_extra'] ?? 0);
        $engaged = (float) ($summary['engaged'] ?? 0);
        $disbursed = (float) ($summary['disbursed'] ?? 0);
        $remaining = $approvedBudget - $disbursed;
        $rate = static fn (float $value, float $budget): float => $budget > 0 ? min(100, round($value / $budget * 100, 1)) : 0;
        $overrunLabels = [
            \App\Models\BudgetOverrunRequest::STATUS_PENDING_DIRECTOR => 'A instruire par la Directrice DAF',
            \App\Models\BudgetOverrunRequest::STATUS_PENDING_DG => 'En attente de decision DG',
            \App\Models\BudgetOverrunRequest::STATUS_APPROVED => 'Approuvee par la DG',
            \App\Models\BudgetOverrunRequest::STATUS_REJECTED => 'Refusee',
        ];
        $overrunTones = [
            \App\Models\BudgetOverrunRequest::STATUS_PENDING_DIRECTOR => 'anbg-badge-warning',
            \App\Models\BudgetOverrunRequest::STATUS_PENDING_DG => 'anbg-badge-info',
            \App\Models\BudgetOverrunRequest::STATUS_APPROVED => 'anbg-badge-success',
            \App\Models\BudgetOverrunRequest::STATUS_REJECTED => 'anbg-badge-danger',
        ];
    @endphp

    <div class="app-screen-flow">
        @if ($errors->any())
            <section class="app-screen-block border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100" role="alert">
                <p class="font-bold">L'operation n'a pas pu etre enregistree.</p>
                <ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </section>
        @endif
        <section class="showcase-hero app-screen-block">
            <div class="showcase-hero-body">
                <div>
                    <span class="showcase-eyebrow">Execution budgetaire</span>
                    <h1 class="showcase-title">Suivi des financements</h1>
                    <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">
                        Les montants planifies constituent le budget de reference. La DAF enregistre les engagements et decaissements justifies ; les depassements suivent le circuit DAF puis DG.
                    </p>
                </div>
                <a class="btn btn-secondary" href="{{ route('workspace.actions.index') }}">Voir les actions</a>
            </div>
        </section>

        <section class="app-screen-block border-y border-slate-200 bg-white/70 dark:border-slate-700 dark:bg-slate-900/70" aria-label="Synthese financiere">
            <div class="grid divide-x divide-y divide-slate-200 sm:grid-cols-2 xl:grid-cols-4 dark:divide-slate-700">
                <div class="min-w-0 px-4 py-3">
                    <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Budget approuve</p>
                    <p class="mt-1 truncate text-xl font-bold text-[#17324a] dark:text-white" title="{{ $money($approvedBudget) }}">{{ $money($approvedBudget) }}</p>
                    @if ((float) ($summary['approved_extra'] ?? 0) > 0)
                        <p class="mt-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">Dont {{ $money($summary['approved_extra']) }} de depassements approuves</p>
                    @endif
                </div>
                <div class="min-w-0 px-4 py-3">
                    <div class="flex items-center justify-between gap-3"><p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Engage</p><span class="text-xs font-bold text-sky-700 dark:text-sky-300">{{ $rate($engaged, $approvedBudget) }} %</span></div>
                    <p class="mt-1 truncate text-xl font-bold text-[#17324a] dark:text-white" title="{{ $money($engaged) }}">{{ $money($engaged) }}</p>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full bg-sky-500" style="width: {{ $rate($engaged, $approvedBudget) }}%"></div></div>
                </div>
                <div class="min-w-0 px-4 py-3">
                    <div class="flex items-center justify-between gap-3"><p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Decaisse</p><span class="text-xs font-bold text-violet-700 dark:text-violet-300">{{ $rate($disbursed, $approvedBudget) }} %</span></div>
                    <p class="mt-1 truncate text-xl font-bold text-[#17324a] dark:text-white" title="{{ $money($disbursed) }}">{{ $money($disbursed) }}</p>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full bg-violet-500" style="width: {{ $rate($disbursed, $approvedBudget) }}%"></div></div>
                </div>
                <div class="min-w-0 px-4 py-3">
                    <p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Solde disponible</p>
                    <p class="mt-1 truncate text-xl font-bold {{ $remaining < 0 ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300' }}" title="{{ $money($remaining) }}">{{ $money($remaining) }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Budget approuve moins decaissements</p>
                </div>
            </div>
        </section>

        <section class="showcase-panel app-screen-block">
            <form method="GET" action="{{ route('workspace.daf.financements.index') }}" class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50/85 p-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_minmax(175px,.8fr)_minmax(175px,.8fr)_auto_auto] dark:border-slate-700 dark:bg-slate-900/70">
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Rechercher une action
                    <input name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Action, PTA..." class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 placeholder:text-slate-400 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Direction
                    <select id="finance-direction-filter" name="direction_id" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Toutes les directions</option>
                        @foreach ($directionOptions as $direction)
                            <option value="{{ $direction->id }}" @selected((int) ($filters['direction_id'] ?? 0) === (int) $direction->id)>{{ $direction->code }} - {{ $direction->libelle }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Service
                    <select id="finance-service-filter" name="service_id" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Tous les services</option>
                        @foreach ($serviceOptions as $service)
                            <option value="{{ $service->id }}" data-direction-id="{{ $service->direction_id }}" @selected((int) ($filters['service_id'] ?? 0) === (int) $service->id)>{{ $service->code }} - {{ $service->libelle }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="btn btn-primary self-end" type="submit">Appliquer</button>
                <a class="btn btn-secondary self-end" href="{{ route('workspace.daf.financements.index') }}">Réinitialiser</a>
            </form>
        </section>

        <section class="showcase-panel app-screen-block" aria-labelledby="finance-actions-title">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="finance-actions-title" class="showcase-panel-title mb-0">Portefeuille des actions</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ number_format($actions->total(), 0, ',', ' ') }} action(s) dans votre perimetre</p>
                </div>
                @if ($selectedAction)
                    <a class="btn btn-secondary" href="{{ route('workspace.daf.financements.index', $filters) }}">Fermer le detail</a>
                @endif
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead><tr><th>Action</th><th>Direction / service</th><th>Budget</th><th>Engage</th><th>Decaisse</th><th>Solde</th><th>Suivi</th></tr></thead>
                    <tbody>
                        @forelse ($actions as $action)
                            @php
                                $rowBudget = (float) ($action->montant_estime ?? 0);
                                $rowEngaged = (float) ($action->engaged_total ?? 0);
                                $rowDisbursed = (float) ($action->disbursed_total ?? 0);
                            @endphp
                            <tr>
                                <td><p class="font-bold text-[#17324a] dark:text-slate-100">{{ $action->libelle }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $action->pta?->titre ?? '-' }}</p></td>
                                <td><p>{{ $action->pta?->direction?->code ?? '-' }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $action->pta?->service?->code ?? '-' }}</p></td>
                                <td class="whitespace-nowrap">{{ $money($rowBudget) }}</td>
                                <td class="whitespace-nowrap">{{ $money($rowEngaged) }}</td>
                                <td class="whitespace-nowrap">{{ $money($rowDisbursed) }}</td>
                                <td class="whitespace-nowrap font-semibold {{ $rowBudget - $rowDisbursed < 0 ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ $money($rowBudget - $rowDisbursed) }}</td>
                                <td><a class="btn btn-secondary" href="{{ route('workspace.daf.financements.index', [...$filters, 'action_id' => $action->id]) }}">Consulter</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-ui.empty-state title="Aucune action budgetee" message="Aucune action de votre perimetre ne correspond aux filtres." icon="filter" tone="info" class="my-4" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $actions->links() }}</div>
        </section>

        @if ($selectedAction)
            <section class="showcase-panel app-screen-block" aria-labelledby="finance-detail-title">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-700">
                    <div><span class="showcase-eyebrow">Détail de l'action</span><h2 id="finance-detail-title" class="showcase-panel-title mb-0">{{ $selectedAction->libelle }}</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $selectedAction->pta?->direction?->libelle }} · {{ $selectedAction->pta?->service?->libelle }}</p></div>
                    <a class="btn btn-secondary" href="{{ route('workspace.actions.suivi', $selectedAction) }}#action-discussion">Donner un avis</a>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach (['Budget approuve' => $selectedSummary['budget'], 'Engage' => $selectedSummary['engaged'], 'Decaisse' => $selectedSummary['disbursed'], 'Solde disponible' => $selectedSummary['remaining']] as $label => $amount)
                        <div class="border-l-2 border-[#3996D3] bg-slate-50 px-3 py-2 dark:bg-slate-950/50"><p class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ $label }}</p><p class="mt-1 text-lg font-bold text-[#17324a] dark:text-white">{{ $money($amount) }}</p></div>
                    @endforeach
                </div>
                <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,.8fr)]">
                    <div>
                        <h3 class="text-base font-bold text-[#17324a] dark:text-white">Operations enregistrees</h3>
                        <div class="mt-3 overflow-x-auto border border-slate-200 dark:border-slate-700"><table class="app-table data-table"><thead><tr><th>Date</th><th>Nature</th><th>Montant</th><th>Reference</th><th>Justificatif</th></tr></thead><tbody>
                            @forelse ($transactions as $transaction)
                                <tr><td>{{ $transaction->operated_on?->format('d/m/Y') }}</td><td>{{ $transaction->operation_type === \App\Models\FinancialTransaction::TYPE_COMMITMENT ? 'Engagement' : 'Decaissement' }}</td><td class="whitespace-nowrap font-semibold">{{ $money($transaction->amount) }}</td><td>{{ $transaction->reference ?: '-' }}<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $transaction->beneficiary ?: '' }}</p></td><td>@if ($transaction->justificatifs->isNotEmpty())<a class="font-semibold text-[#176A9D] hover:underline dark:text-sky-300" href="{{ route('workspace.finances.transactions.proof.download', [$transaction, $transaction->justificatifs->first()]) }}">Télécharger</a>@else - @endif</td></tr>
                            @empty
                                <tr><td colspan="5" class="text-slate-500 dark:text-slate-400">Aucune operation enregistree.</td></tr>
                            @endforelse
                        </tbody></table></div>
                    </div>
                    @if ($canRecord)
                        <form method="POST" action="{{ route('workspace.finances.transactions.store', $selectedAction) }}" enctype="multipart/form-data" class="rounded-lg border border-slate-200 bg-slate-50/85 p-4 dark:border-slate-700 dark:bg-slate-900/70">
                            @csrf
                            <h3 class="text-base font-bold text-[#17324a] dark:text-white">Enregistrer une operation DAF</h3>
                            <div class="mt-3 grid gap-3">
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Nature<select name="operation_type" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"><option value="engagement">Engagement</option><option value="decaissement">Decaissement</option></select></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Montant FCFA<input name="amount" type="number" min="0.01" step="0.01" required class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Date<input name="operated_on" type="date" value="{{ now()->toDateString() }}" required class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Mode de paiement<select name="payment_method" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"><option value="">Non precise</option><option value="virement">Virement</option><option value="cheque">Cheque</option><option value="especes">Especes</option><option value="ordre_paiement">Ordre de paiement</option><option value="autre">Autre</option></select></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Reference<input name="reference" maxlength="255" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Beneficiaire<input name="beneficiary" maxlength="255" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Justificatif (obligatoire pour un decaissement)<input name="proof" type="file" class="block w-full text-sm font-normal normal-case text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-sky-100 file:px-3 file:py-2 file:font-semibold file:text-sky-800 dark:text-slate-200 dark:file:bg-sky-950 dark:file:text-sky-200"></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Observation<textarea name="comment" rows="2" maxlength="3000" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></textarea></label>
                                <button class="btn btn-primary justify-center" type="submit">Enregistrer l'operation</button>
                            </div>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        @if ($canRecord || $isDafDirector || $isDg)
            <section class="showcase-panel app-screen-block" aria-labelledby="overrun-title">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="overrun-title" class="showcase-panel-title mb-0">Depassements budgetaires</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Circuit : Chef de service DAF → Directrice DAF → DG.</p></div></div>
                <div class="mt-4 grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,.8fr)]">
                    <div class="overflow-x-auto border border-slate-200 dark:border-slate-700"><table class="app-table data-table"><thead><tr><th>Périmètre</th><th>Montant supplementaire</th><th>Demandeur</th><th>Statut</th><th>Decision</th></tr></thead><tbody>
                        @forelse ($overruns as $overrun)
                            <tr><td>{{ ucfirst($overrun->scope_type) }} #{{ $overrun->scope_id }}<p class="mt-1 max-w-xs text-xs text-slate-500 dark:text-slate-400">{{ $overrun->reason }}</p>@if ($overrun->justificatifs->isNotEmpty())<a class="mt-1 inline-block text-xs font-semibold text-[#176A9D] hover:underline dark:text-sky-300" href="{{ route('workspace.finances.overruns.proof.download', [$overrun, $overrun->justificatifs->first()]) }}">Voir la piece</a>@endif</td><td class="whitespace-nowrap">{{ $money($overrun->requested_extra) }}</td><td>{{ $overrun->requestedBy?->name ?? '-' }}</td><td><span class="anbg-badge {{ $overrunTones[$overrun->status] ?? 'anbg-badge-neutral' }} px-2 py-1 text-xs">{{ $overrunLabels[$overrun->status] ?? $overrun->status }}</span></td><td class="min-w-[185px]">
                                @if (($isDafDirector && $overrun->status === \App\Models\BudgetOverrunRequest::STATUS_PENDING_DIRECTOR) || ($isDg && $overrun->status === \App\Models\BudgetOverrunRequest::STATUS_PENDING_DG))
                                    <form method="POST" action="{{ route('workspace.finances.overruns.review', $overrun) }}" class="grid gap-2">@csrf<label class="sr-only" for="note-{{ $overrun->id }}">Note de decision</label><textarea id="note-{{ $overrun->id }}" name="note" rows="2" required minlength="5" placeholder="Motif de la decision" class="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></textarea><div class="flex flex-wrap gap-2">@if ($isDafDirector)<button class="btn btn-primary" name="decision" value="transmit" type="submit">Transmettre DG</button>@endif<button class="btn btn-danger" name="decision" value="reject" type="submit">Refuser</button>@if ($isDg)<button class="btn btn-primary" name="decision" value="approve" type="submit">Approuver</button>@endif</div></form>
                                @else
                                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ $overrun->dg_note ?: $overrun->daf_director_note ?: '-' }}</span>
                                @endif
                            </td></tr>
                        @empty
                            <tr><td colspan="5" class="text-slate-500 dark:text-slate-400">Aucune demande de depassement recente.</td></tr>
                        @endforelse
                    </tbody></table></div>
                    @if ($canRecord)
                        <form method="POST" action="{{ route('workspace.finances.overruns.store') }}" enctype="multipart/form-data" class="rounded-lg border border-slate-200 bg-slate-50/85 p-4 dark:border-slate-700 dark:bg-slate-900/70">
                            @csrf
                            <h3 class="text-base font-bold text-[#17324a] dark:text-white">Nouvelle demande</h3>
                            <div class="mt-3 grid gap-3"><label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Périmètre<select id="overrun-scope-type" name="scope_type" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"><option value="action">Action</option><option value="service">Service</option><option value="direction">Direction</option></select></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Element concerne<select id="overrun-scope-id" name="scope_id" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></select></label>
                                <template id="overrun-options-action">@foreach ($actionOptions as $action)<option value="{{ $action->id }}">{{ \Illuminate\Support\Str::limit($action->libelle, 68) }}</option>@endforeach</template><template id="overrun-options-service">@foreach ($serviceOptions as $service)<option value="{{ $service->id }}">{{ $service->code }} - {{ $service->libelle }}</option>@endforeach</template><template id="overrun-options-direction">@foreach ($directionOptions as $direction)<option value="{{ $direction->id }}">{{ $direction->code }} - {{ $direction->libelle }}</option>@endforeach</template>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Montant supplementaire FCFA<input name="requested_extra" type="number" min="0.01" step="0.01" required class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Justification<textarea name="reason" rows="3" required minlength="10" maxlength="3000" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal normal-case text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></textarea></label>
                                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Piece justificative<input name="proof" type="file" class="block w-full text-sm font-normal normal-case text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-sky-100 file:px-3 file:py-2 file:font-semibold file:text-sky-800 dark:text-slate-200 dark:file:bg-sky-950 dark:file:text-sky-200"></label><button class="btn btn-primary justify-center" type="submit">Soumettre la demande</button></div>
                        </form>
                    @endif
                </div>
            </section>
        @endif
    </div>
@endsection

@push('scripts')
    <script @cspNonce>
        (function () {
            var direction = document.getElementById('finance-direction-filter');
            var service = document.getElementById('finance-service-filter');
            if (direction && service) {
                var syncServices = function () {
                    var selectedDirection = String(direction.value || '');
                    Array.prototype.forEach.call(service.options, function (option, index) {
                        var visible = index === 0 || selectedDirection === '' || option.getAttribute('data-direction-id') === selectedDirection;
                        option.hidden = !visible;
                        option.disabled = !visible;
                    });
                    if (service.selectedOptions[0] && service.selectedOptions[0].disabled) service.value = '';
                };
                direction.addEventListener('change', syncServices);
                syncServices();
            }

            var scopeType = document.getElementById('overrun-scope-type');
            var scopeId = document.getElementById('overrun-scope-id');
            if (scopeType && scopeId) {
                var syncScopeOptions = function () {
                    var source = document.getElementById('overrun-options-' + scopeType.value);
                    scopeId.innerHTML = source ? source.innerHTML : '';
                };
                scopeType.addEventListener('change', syncScopeOptions);
                syncScopeOptions();
            }
        }());
    </script>
@endpush
