@extends('layouts.workspace')

@section('content')
    @php
        $isEdit = $mode === 'edit';
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);

        $initialAxes = old('axes', $axesPayload ?? []);
        if (! is_array($initialAxes) || count($initialAxes) === 0) {
            $initialAxes = [[
                'code' => '',
                'libelle' => '',
                'periode_debut' => '',
                'periode_fin' => '',
                'description' => '',
                'ordre' => 1,
                'objectifs' => [[
                    'code' => '',
                    'libelle' => '',
                    'description' => '',
                    'ordre' => 1,
                    'indicateur_global' => '',
                    'valeur_cible' => '',
                    'valeurs_cible' => [
                        'taux_realisation' => '',
                        'budget' => '',
                    ],
                ]],
            ]];
        }
    @endphp

    <div class="app-screen-flow">
    <section class="showcase-hero mb-4 app-screen-block">
        <div class="showcase-hero-body">
            <div>
                <span class="showcase-eyebrow">PAS</span>
                <h1 class="showcase-title">{{ $isEdit ? 'Modifier un PAS existant' : 'Enregistrer un nouveau PAS' }}</h1>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-blue" href="{{ route('workspace.pas.index') }}">Retour liste</a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Mode</p>
            <p class="showcase-kpi-number">{{ $isEdit ? 'Edit.' : 'Nouv.' }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Periode</p>
            <p class="showcase-kpi-number text-[1.35rem]">{{ old('periode_debut', $row->periode_debut) ?: '--' }} - {{ old('periode_fin', $row->periode_fin) ?: '--' }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Axes prepares</p>
            <p class="showcase-kpi-number">{{ count($initialAxes) }}</p>
        </article>
        <article class="showcase-kpi-card">
            <p class="showcase-kpi-label">Workflow</p>
            <p class="showcase-kpi-number text-[1.35rem]">{{ $workflowStatusLabel((string) old('statut', $row->statut ?: 'brouillon')) }}</p>
        </article>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <form method="POST" class="form-shell" action="{{ $isEdit ? route('workspace.pas.update', $row) : route('workspace.pas.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="mb-4 grid gap-3 md:grid-cols-3" id="pas-wizard-nav">
                <button type="button" class="wizard-step-card" data-wizard-step="pas">
                    <span class="wizard-step-index">1</span>
                    <span class="wizard-step-text">
                        <strong>Plan strategique</strong>
                        <small>Identite et periode</small>
                    </span>
                </button>
                <button type="button" class="wizard-step-card" data-wizard-step="axes">
                    <span class="wizard-step-index">2</span>
                    <span class="wizard-step-text">
                        <strong>Axes</strong>
                        <small>Structurer le plan</small>
                    </span>
                </button>
                <button type="button" class="wizard-step-card" data-wizard-step="objectifs">
                    <span class="wizard-step-index">3</span>
                    <span class="wizard-step-text">
                        <strong>Objectifs strategiques</strong>
                        <small>Declarer les objectifs</small>
                    </span>
                </button>
            </div>

            <div class="form-section" data-step="pas">
                <h2 class="form-section-title">Informations strategiques</h2>
                <div class="grid gap-4">
                    <div>
                        <label for="titre">Titre</label>
                        <input id="titre" name="titre" type="text" value="{{ old('titre', $row->titre) }}" required>
                    </div>
                    <div>
                        <label for="periode_debut">Periode debut</label>
                        <input id="periode_debut" name="periode_debut" type="number" value="{{ old('periode_debut', $row->periode_debut) }}" min="2000" required>
                    </div>
                    <div>
                        <label for="periode_fin">Periode fin</label>
                        <input id="periode_fin" name="periode_fin" type="number" value="{{ old('periode_fin', $row->periode_fin) }}" min="2000" required>
                    </div>
                    <div>
                        <label for="statut">Statut</label>
                        <select id="statut" name="statut" required>
                            @foreach ($statusOptions as $status)
                                <option value="{{ $status }}" @selected(old('statut', $row->statut ?: 'brouillon') === $status)>{{ $workflowStatusLabel($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section" data-step="structure">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="form-section-title" id="structure-title">Axes strategiques</h2>
                    </div>
                    <button
                        type="button"
                        id="add-axe"
                        data-wizard-visible="axes"
                        class="btn btn-primary"
                    >
                        Ajouter un axe
                    </button>
                </div>

                <div id="axes-list" class="mt-4 space-y-4"></div>
            </div>

            <div class="form-actions">
                <button id="wizard-prev" class="btn btn-secondary" type="button">Etape precedente</button>
                <button id="wizard-next" class="btn btn-primary" type="button">Etape suivante</button>
                <button id="wizard-submit" class="btn btn-primary" type="submit">{{ $isEdit ? 'Mettre a jour' : 'Creer' }}</button>
                <a class="btn btn-secondary" href="{{ route('workspace.pas.index') }}">Retour</a>
            </div>
        </form>
    </section>

    @if ($isEdit)
        <section class="showcase-panel mb-4 app-screen-block">
            <h2 class="showcase-panel-title">Timeline validation</h2>
            <div class="overflow-auto">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Action</th>
                            <th>Transition statut</th>
                            <th>Motif retour</th>
                            <th>Par</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($timeline as $item)
                            <tr>
                                <td>{{ $item['date'] ?? '-' }}</td>
                                <td><span class="inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-800">{{ $item['action'] }}</span></td>
                                <td>
                                    @if (!empty($item['from']) || !empty($item['to']))
                                        {{ !empty($item['from']) ? $workflowStatusLabel((string) $item['from']) : '-' }} -> {{ !empty($item['to']) ? $workflowStatusLabel((string) $item['to']) : '-' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>{{ $item['reason'] ?? '-' }}</td>
                                <td>{{ $item['user'] }} ({{ $item['user_role'] }})</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-slate-600">Aucune transition enregistree.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
    </div>
@endsection

@push('styles')
    <style>
        .wizard-step-card {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            width: 100%;
            padding: 1rem;
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.78);
            text-align: left;
            transition: all 0.2s ease;
        }

        .dark .wizard-step-card {
            background: rgba(15, 23, 42, 0.7);
            border-color: rgba(71, 85, 105, 0.65);
        }

        .wizard-step-card.is-active {
            border-color: rgba(37, 99, 235, 0.55);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.14);
            background: linear-gradient(135deg, rgba(219, 234, 254, 0.9), rgba(255, 255, 255, 0.92));
        }

        .dark .wizard-step-card.is-active {
            background: linear-gradient(135deg, rgba(30, 64, 175, 0.28), rgba(15, 23, 42, 0.92));
        }

        .wizard-step-index {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 9999px;
            background: linear-gradient(135deg, rgba(29, 78, 216, 1), rgba(14, 116, 144, 1));
            color: #fff;
            font-size: 0.875rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .wizard-step-text {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            min-width: 0;
        }

        .wizard-step-text strong {
            font-size: 0.95rem;
            color: rgb(15 23 42);
        }

        .wizard-step-text small {
            font-size: 0.78rem;
            color: rgb(100 116 139);
        }

        .dark .wizard-step-text strong {
            color: rgb(241 245 249);
        }

        .dark .wizard-step-text small {
            color: rgb(148 163 184);
        }
    </style>
@endpush

@push('scripts')
    <script>
        (function () {
            var debut = document.getElementById('periode_debut');
            var fin = document.getElementById('periode_fin');

            function syncPeriodRange() {
                if (!debut || !fin) {
                    return;
                }

                var debutValue = parseInt(debut.value || '0', 10);
                if (debutValue > 0) {
                    fin.min = String(debutValue);
                    if (fin.value && parseInt(fin.value, 10) < debutValue) {
                        fin.value = String(debutValue);
                    }
                }
            }

            function normalizePasYear(value, fallback) {
                var year = parseInt(String(value || '').slice(0, 4), 10);
                if (Number.isNaN(year) || year <= 0) {
                    return fallback;
                }

                return year;
            }

            function getPasStartDate() {
                return String(normalizePasYear(debut ? debut.value : '', new Date().getFullYear())) + '-01-01';
            }

            function getPasEndDate() {
                return String(normalizePasYear(fin ? fin.value : '', new Date().getFullYear())) + '-12-31';
            }

            function syncAxisPeriodConstraints() {
                var pasStartDate = getPasStartDate();
                var pasEndDate = getPasEndDate();

                Array.prototype.forEach.call(document.querySelectorAll('.axe-periode-debut'), function (input) {
                    input.min = pasStartDate;
                    input.max = pasEndDate;

                    if (!input.value) {
                        input.value = pasStartDate;
                    }
                });

                Array.prototype.forEach.call(document.querySelectorAll('.axe-periode-fin'), function (input) {
                    var axeCard = input.closest('[data-axe-index]');
                    var startInput = axeCard ? axeCard.querySelector('.axe-periode-debut') : null;
                    var currentMin = startInput && startInput.value ? startInput.value : pasStartDate;

                    input.min = currentMin;
                    input.max = pasEndDate;

                    if (!input.value) {
                        input.value = pasEndDate;
                    } else if (input.value < currentMin) {
                        input.value = currentMin;
                    }
                });
            }

            if (debut) {
                debut.addEventListener('input', function () {
                    syncPeriodRange();
                    syncAxisPeriodConstraints();
                });
            }

            if (fin) {
                fin.addEventListener('input', function () {
                    syncPeriodRange();
                    syncAxisPeriodConstraints();
                });
            }

            syncPeriodRange();

            var axesList = document.getElementById('axes-list');
            var addAxeButton = document.getElementById('add-axe');
            var axesData = @json($initialAxes);
            var pasSection = document.querySelector('[data-step="pas"]');
            var structureSection = document.querySelector('[data-step="structure"]');
            var structureTitle = document.getElementById('structure-title');
            var structureSubtitle = document.getElementById('structure-subtitle');
            var wizardButtons = Array.prototype.slice.call(document.querySelectorAll('[data-wizard-step]'));
            var prevButton = document.getElementById('wizard-prev');
            var nextButton = document.getElementById('wizard-next');
            var submitButton = document.getElementById('wizard-submit');
            var wizardSteps = ['pas', 'axes', 'objectifs'];
            var currentWizardStep = wizardSteps[0];

            if (!axesList || !addAxeButton || !Array.isArray(axesData)) {
                return;
            }

            var axeCounter = 0;

            function setDisplay(element, visible, mode) {
                if (!element) {
                    return;
                }

                element.style.display = visible ? (mode || '') : 'none';
            }

            function refreshWizard() {
                var currentIndex = wizardSteps.indexOf(currentWizardStep);
                var isAxesStep = currentWizardStep === 'axes';
                var isObjectifsStep = currentWizardStep === 'objectifs';

                setDisplay(pasSection, currentWizardStep === 'pas', 'block');
                setDisplay(structureSection, isAxesStep || isObjectifsStep, 'block');
                setDisplay(addAxeButton, isAxesStep, 'inline-flex');

                if (structureTitle) {
                    structureTitle.textContent = isAxesStep ? 'Axes strategiques' : 'Objectifs strategiques';
                }

                if (structureSubtitle) {
                    structureSubtitle.textContent = isAxesStep
                        ? 'Creez d abord la structure des axes du PAS. Les directions ne sont pas renseignees a ce niveau.'
                        : 'Pour chaque axe, renseignez les objectifs strategiques qui ouvriront ensuite la creation des PAO par direction.';
                }

                wizardButtons.forEach(function (button) {
                    var active = button.getAttribute('data-wizard-step') === currentWizardStep;
                    button.classList.toggle('is-active', active);
                    button.setAttribute('aria-current', active ? 'step' : 'false');
                });

                setDisplay(prevButton, currentIndex > 0, 'inline-flex');
                setDisplay(nextButton, currentIndex < wizardSteps.length - 1, 'inline-flex');
                setDisplay(submitButton, currentIndex === wizardSteps.length - 1, 'inline-flex');

                Array.prototype.forEach.call(axesList.querySelectorAll('[data-axe-index]'), function (axeCard) {
                    Array.prototype.forEach.call(axeCard.querySelectorAll('[data-wizard-block=\"axes\"]'), function (block) {
                        setDisplay(block, isAxesStep, 'block');
                    });

                    Array.prototype.forEach.call(axeCard.querySelectorAll('[data-wizard-block=\"objectifs\"]'), function (block) {
                        setDisplay(block, isObjectifsStep, 'block');
                    });

                    Array.prototype.forEach.call(axeCard.querySelectorAll('.remove-axe'), function (button) {
                        setDisplay(button, isAxesStep, 'inline-flex');
                    });

                    Array.prototype.forEach.call(axeCard.querySelectorAll('.add-objectif, .remove-objectif'), function (button) {
                        setDisplay(button, isObjectifsStep, 'inline-flex');
                    });
                });
            }

            function goToWizardStep(step) {
                if (wizardSteps.indexOf(step) === -1) {
                    return;
                }

                currentWizardStep = step;
                refreshWizard();
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function createObjectifHtml(axeIndex, objectifIndex, objectif) {
                var targetValues = objectif && typeof objectif.valeurs_cible === 'object' && objectif.valeurs_cible !== null
                    ? objectif.valeurs_cible
                    : {};

                return '' +
                    '<div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700" data-objectif-index="' + objectifIndex + '">' +
                        '<div class="mb-2 flex items-center justify-between gap-2">' +
                            '<h4 class="text-sm font-semibold">Objectif strategique</h4>' +
                            '<button type="button" class="remove-objectif inline-flex items-center justify-center rounded-md bg-[#f9b13c] px-2 py-1 text-xs font-medium text-white hover:bg-[#f9b13c]">Supprimer</button>' +
                        '</div>' +
                        '<div class="form-grid">' +
                            '<div>' +
                                '<label>Code objectif</label>' +
                                '<input type="text" name="axes[' + axeIndex + '][objectifs][' + objectifIndex + '][code]" value="' + escapeHtml(objectif.code || '') + '" maxlength="30" placeholder="Ex: OS-1.1">' +
                            '</div>' +
                            '<div>' +
                                '<label>Libelle objectif</label>' +
                                '<input type="text" name="axes[' + axeIndex + '][objectifs][' + objectifIndex + '][libelle]" value="' + escapeHtml(objectif.libelle || '') + '" required>' +
                            '</div>' +
                            '<div>' +
                                '<label>Ordre objectif</label>' +
                                '<input type="number" min="1" name="axes[' + axeIndex + '][objectifs][' + objectifIndex + '][ordre]" value="' + escapeHtml(objectif.ordre || (objectifIndex + 1)) + '">' +
                            '</div>' +
                            '<div>' +
                                '<label>Indicateur global</label>' +
                                '<input type="text" name="axes[' + axeIndex + '][objectifs][' + objectifIndex + '][indicateur_global]" value="' + escapeHtml(objectif.indicateur_global || '') + '">' +
                            '</div>' +
                            '<div>' +
                                '<label>Valeur cible</label>' +
                                '<input type="text" name="axes[' + axeIndex + '][objectifs][' + objectifIndex + '][valeur_cible]" value="' + escapeHtml(objectif.valeur_cible || '') + '" placeholder="Ex: 90%">' +
                            '</div>' +
                            '<div>' +
                                '<label>Taux realisation cible</label>' +
                                '<input type="text" name="axes[' + axeIndex + '][objectifs][' + objectifIndex + '][valeurs_cible][taux_realisation]" value="' + escapeHtml(targetValues.taux_realisation || '') + '" placeholder="Ex: 80">' +
                            '</div>' +
                            '<div>' +
                                '<label>Budget cible</label>' +
                                '<input type="text" name="axes[' + axeIndex + '][objectifs][' + objectifIndex + '][valeurs_cible][budget]" value="' + escapeHtml(targetValues.budget || '') + '" placeholder="Ex: 500000">' +
                            '</div>' +
                            '<div class="md:col-span-2">' +
                                '<label>Description</label>' +
                                '<textarea name="axes[' + axeIndex + '][objectifs][' + objectifIndex + '][description]">' + escapeHtml(objectif.description || '') + '</textarea>' +
                            '</div>' +
                            '<div class="md:col-span-2">' +
                                '<p class="text-xs text-slate-500 dark:text-slate-400">Les champs ci-dessus alimentent le JSON <code>valeurs_cible</code> tout en conservant les champs historiques pour compatibilite.</p>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
            }

            function appendObjectif(axeCard, objectif) {
                var axeIndex = axeCard.getAttribute('data-axe-index');
                var objectifList = axeCard.querySelector('.objectifs-list');
                var nextObjectifIndex = parseInt(axeCard.getAttribute('data-next-objectif-index') || '0', 10);
                var payload = objectif && typeof objectif === 'object' ? objectif : {};
                var wrapper = document.createElement('div');

                wrapper.innerHTML = createObjectifHtml(axeIndex, nextObjectifIndex, payload);
                objectifList.appendChild(wrapper.firstElementChild);
                axeCard.setAttribute('data-next-objectif-index', String(nextObjectifIndex + 1));
            }

            function createAxeCard(axe) {
                var axeIndex = axeCounter++;
                var payload = axe && typeof axe === 'object' ? axe : {};
                var objectifs = Array.isArray(payload.objectifs) && payload.objectifs.length > 0
                    ? payload.objectifs
                    : [{}];

                var card = document.createElement('div');
                card.className = 'rounded-xl border border-slate-200 p-4 dark:border-slate-700';
                card.setAttribute('data-axe-index', String(axeIndex));
                card.setAttribute('data-next-objectif-index', '0');
                card.innerHTML = '' +
                    '<div class="mb-3 flex items-center justify-between gap-2">' +
                        '<h3 class="text-base font-semibold">Axe strategique</h3>' +
                        '<button type="button" class="remove-axe inline-flex items-center justify-center rounded-md bg-[#f9b13c] px-2 py-1 text-xs font-medium text-white hover:bg-[#f9b13c]">Supprimer axe</button>' +
                    '</div>' +
                    '<div data-wizard-block="axes">' +
                        '<div class="form-grid">' +
                            '<div>' +
                                '<label>Code axe</label>' +
                                '<input type="text" name="axes[' + axeIndex + '][code]" value="' + escapeHtml(payload.code || '') + '" maxlength="30" placeholder="Ex: AXE-1">' +
                            '</div>' +
                            '<div>' +
                                '<label>Libelle axe</label>' +
                                '<input type="text" name="axes[' + axeIndex + '][libelle]" value="' + escapeHtml(payload.libelle || '') + '" required>' +
                            '</div>' +
                            '<div>' +
                                '<label>Ordre</label>' +
                                '<input type="number" min="1" name="axes[' + axeIndex + '][ordre]" value="' + escapeHtml(payload.ordre || (axeIndex + 1)) + '">' +
                            '</div>' +
                            '<div>' +
                                '<label>Periode debut axe</label>' +
                                '<input class="axe-periode-debut" type="date" name="axes[' + axeIndex + '][periode_debut]" value="' + escapeHtml(payload.periode_debut || getPasStartDate()) + '">' +
                            '</div>' +
                            '<div>' +
                                '<label>Periode fin axe</label>' +
                                '<input class="axe-periode-fin" type="date" name="axes[' + axeIndex + '][periode_fin]" value="' + escapeHtml(payload.periode_fin || getPasEndDate()) + '">' +
                            '</div>' +
                            '<div class="md:col-span-2">' +
                                '<label>Description axe</label>' +
                                '<textarea name="axes[' + axeIndex + '][description]">' + escapeHtml(payload.description || '') + '</textarea>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="mt-4 rounded-lg bg-slate-100 p-3 dark:bg-slate-900/60" data-wizard-block="objectifs">' +
                        '<div class="mb-2 flex items-center justify-between gap-2">' +
                            '<h4 class="text-sm font-semibold">Objectifs strategiques</h4>' +
                            '<button type="button" class="add-objectif inline-flex items-center justify-center rounded-md bg-[#3B82F6] px-2 py-1 text-xs font-medium text-white hover:bg-[#1E3A8A]">Ajouter objectif</button>' +
                        '</div>' +
                        '<div class="objectifs-list space-y-3"></div>' +
                    '</div>';

                axesList.appendChild(card);

                objectifs.forEach(function (objectif) {
                    appendObjectif(card, objectif);
                });

                syncAxisPeriodConstraints();
            }

            axesData.forEach(function (axe) {
                createAxeCard(axe);
            });

            wizardButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    goToWizardStep(button.getAttribute('data-wizard-step') || 'pas');
                });
            });

            if (prevButton) {
                prevButton.addEventListener('click', function () {
                    var currentIndex = wizardSteps.indexOf(currentWizardStep);
                    goToWizardStep(wizardSteps[Math.max(0, currentIndex - 1)]);
                });
            }

            if (nextButton) {
                nextButton.addEventListener('click', function () {
                    var currentIndex = wizardSteps.indexOf(currentWizardStep);
                    goToWizardStep(wizardSteps[Math.min(wizardSteps.length - 1, currentIndex + 1)]);
                });
            }

            addAxeButton.addEventListener('click', function () {
                createAxeCard({});
                refreshWizard();
            });

            axesList.addEventListener('click', function (event) {
                var target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }

                if (target.classList.contains('remove-axe')) {
                    var axeCard = target.closest('[data-axe-index]');
                    if (axeCard) {
                        axeCard.remove();
                        refreshWizard();
                    }
                    return;
                }

                if (target.classList.contains('add-objectif')) {
                    var parentCard = target.closest('[data-axe-index]');
                    if (parentCard) {
                        appendObjectif(parentCard, {});
                        refreshWizard();
                    }
                    return;
                }

                if (target.classList.contains('remove-objectif')) {
                    var objectifCard = target.closest('[data-objectif-index]');
                    if (objectifCard) {
                        objectifCard.remove();
                        refreshWizard();
                    }
                    return;
                }

                if (target.classList.contains('axe-periode-debut')) {
                    syncAxisPeriodConstraints();
                }
            });

            axesList.addEventListener('input', function (event) {
                var target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }

                if (target.classList.contains('axe-periode-debut') || target.classList.contains('axe-periode-fin')) {
                    syncAxisPeriodConstraints();
                }
            });

            syncAxisPeriodConstraints();
            refreshWizard();
        })();
    </script>
@endpush
