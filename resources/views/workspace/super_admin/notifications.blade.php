@extends('layouts.workspace')

@section('title', 'Alertes et notifications')

@section('content')
    <section class="ui-card mb-3.5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Super Administration</p>
                <h1 class="mt-2">Alertes et notifications</h1>
                <p class="mt-2 text-slate-600">Pilotage des evenements emis et de la surcouche d escalade sur les alertes d action. Les destinataires metier natifs restent conserves; les roles ci-dessous s ajoutent en surveillance.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Acces'])
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.index') }}">Retour module</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.referentials.edit') }}">Referentiels dynamiques</a>
                <a class="btn btn-secondary" href="{{ route('workspace.super-admin.workflow.edit') }}">Workflow</a>
            </div>
        </div>
    </section>

    <section class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] mb-3.5">
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Evenements actifs</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['events_enabled'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Nombre d emissions actuellement autorisees.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Niveaux d alerte actifs</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['levels_enabled'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Warning, critique et urgence peuvent etre coupes independamment.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Roles de surveillance additionnels</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['oversight_roles'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Comptes globaux ajoutes en plus des destinataires metier natifs.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Regles d escalade actives</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['rules_enabled'] }}</p>
            <p class="mt-2 text-sm text-slate-600">Recipients et message ajustes par niveau via le moteur de regles.</p>
        </article>
        <article class="ui-card !mb-0">
            <p class="text-sm text-slate-500">Regles temporelles actives</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $summary['timeline_rules_enabled'] ?? 0 }}</p>
            <p class="mt-2 text-sm text-slate-600">Seuils J-n et J+n relies aux echeances des actions.</p>
        </article>
    </section>

    <section class="ui-card mb-3.5">
        @php
            $ruleRows = collect($settings['alert_escalation_rules'] ?? [])->values();
            while ($ruleRows->count() < 5) {
                $ruleRows->push([
                    'code' => '',
                    'level' => 'warning',
                    'target_role' => 'planification',
                    'message_template' => '',
                    'active' => false,
                ]);
            }

            $timelineRows = collect($settings['alert_timeline_rules'] ?? [])->values();
            while ($timelineRows->count() < 5) {
                $timelineRows->push([
                    'code' => '',
                    'offset_days' => 0,
                    'level' => 'warning',
                    'target_role' => 'service',
                    'message_template' => '',
                    'active' => false,
                ]);
            }
        @endphp
        <form method="POST" action="{{ route('workspace.super-admin.notifications.update') }}" class="form-shell">
            @csrf
            @method('PUT')

            <div class="form-section">
                <h2 class="form-section-title">Evenements emis</h2>
                <p class="form-section-subtitle">Couper un evenement empeche l envoi des notifications correspondantes sans modifier le workflow ou les journaux eux-memes.</p>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($events as $eventCode => $definition)
                        @php($selectedChannels = is_array($settings['event_'.$eventCode.'_channels'] ?? null) ? ($settings['event_'.$eventCode.'_channels'] ?? []) : (json_decode((string) ($settings['event_'.$eventCode.'_channels'] ?? '[]'), true) ?: []))
                        <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">
                            <label class="flex items-start gap-3">
                                <input
                                    class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                    type="checkbox"
                                    name="event_{{ $eventCode }}_enabled"
                                    value="1"
                                    @checked(($settings['event_'.$eventCode.'_enabled'] ?? '1') === '1')
                                >
                                <span>
                                    <strong class="block text-slate-900 dark:text-slate-100">{{ $definition['label'] }}</strong>
                                    <span class="mt-1 block text-slate-500 dark:text-slate-400">{{ $definition['description'] }}</span>
                                    <span class="mt-2 inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $definition['group'] }}</span>
                                </span>
                            </label>
                            <div class="mt-4 space-y-3">
                                <div>
                                    <label for="event_title_{{ $eventCode }}">Titre</label>
                                    <input id="event_title_{{ $eventCode }}" name="event_{{ $eventCode }}_title" type="text" maxlength="120" value="{{ old('event_'.$eventCode.'_title', $settings['event_'.$eventCode.'_title'] ?? $definition['label']) }}">
                                </div>
                                <div>
                                    <label for="event_message_{{ $eventCode }}">Message template</label>
                                    <input id="event_message_{{ $eventCode }}" name="event_{{ $eventCode }}_message" type="text" maxlength="255" value="{{ old('event_'.$eventCode.'_message', $settings['event_'.$eventCode.'_message'] ?? '') }}" placeholder="Variables: {action_label}, {actor_name}, {decision}, {level}, {message}">
                                </div>
                                <div>
                                    <span class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Canaux</span>
                                    <div class="grid gap-2 md:grid-cols-2">
                                        @foreach ($eventChannels as $channelCode => $channelLabel)
                                            <label class="checkbox-pill">
                                                <input type="checkbox" name="event_{{ $eventCode }}_channels[]" value="{{ $channelCode }}" @checked(in_array($channelCode, old('event_'.$eventCode.'_channels', $selectedChannels), true))>
                                                <span>{{ $channelLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Escalade des alertes action</h2>
                <p class="form-section-subtitle">Les roles selectionnes sont ajoutes aux destinataires calcules par le moteur d alerte pour chaque niveau.</p>
                <div class="space-y-4">
                    @foreach ($alertLevels as $level => $definition)
                        @php($selectedRoles = is_array($settings['alert_'.$level.'_roles'] ?? null) ? ($settings['alert_'.$level.'_roles'] ?? []) : (json_decode((string) ($settings['alert_'.$level.'_roles'] ?? '[]'), true) ?: []))
                        <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 dark:border-slate-700 dark:bg-slate-900/40">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <label class="flex items-start gap-3 text-sm text-slate-700 dark:text-slate-200">
                                    <input
                                        class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                        type="checkbox"
                                        name="alert_{{ $level }}_enabled"
                                        value="1"
                                        @checked(($settings['alert_'.$level.'_enabled'] ?? '1') === '1')
                                    >
                                    <span>
                                        <strong class="block text-slate-900 dark:text-slate-100">{{ $definition['label'] }}</strong>
                                        <span class="mt-1 block text-slate-500 dark:text-slate-400">{{ $definition['description'] }}</span>
                                    </span>
                                </label>
                            </div>
                            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($roleOptions as $roleCode => $roleLabel)
                                    <label class="checkbox-pill">
                                        <input
                                            type="checkbox"
                                            name="alert_{{ $level }}_roles[]"
                                            value="{{ $roleCode }}"
                                            @checked(in_array($roleCode, $selectedRoles, true))
                                        >
                                        <span>{{ $roleLabel }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Moteur de regles d escalade</h2>
                <p class="form-section-subtitle">Chaque regle ajoute des destinataires au circuit natif et peut surcharger le message envoye. Variables supportees : <code>{action_label}</code>, <code>{level}</code>, <code>{event}</code>, <code>{message}</code>.</p>
                <div class="space-y-4">
                    @foreach ($ruleRows as $index => $rule)
                        <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 dark:border-slate-700 dark:bg-slate-900/40">
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <div>
                                    <label for="rule_level_{{ $index }}">Niveau</label>
                                    <select id="rule_level_{{ $index }}" name="escalation_rules[{{ $index }}][level]">
                                        @foreach ($alertLevels as $levelCode => $definition)
                                            <option value="{{ $levelCode }}" @selected(old("escalation_rules.$index.level", $rule['level'] ?? 'warning') === $levelCode)>{{ $definition['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="rule_target_{{ $index }}">Destinataire ajoute</label>
                                    <select id="rule_target_{{ $index }}" name="escalation_rules[{{ $index }}][target_role]">
                                        <option value="">Aucun</option>
                                        @foreach ($ruleTargetOptions as $roleCode => $roleLabel)
                                            <option value="{{ $roleCode }}" @selected(old("escalation_rules.$index.target_role", $rule['target_role'] ?? '') === $roleCode)>{{ $roleLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="xl:col-span-2">
                                    <label for="rule_message_{{ $index }}">Message template</label>
                                    <input id="rule_message_{{ $index }}" name="escalation_rules[{{ $index }}][message_template]" type="text" value="{{ old("escalation_rules.$index.message_template", $rule['message_template'] ?? '') }}" placeholder="Ex. {level} - {action_label} : {message}">
                                </div>
                                <div class="md:col-span-2 xl:col-span-4 flex items-end gap-3">
                                    <label class="checkbox-pill !mb-0">
                                        <input type="checkbox" name="escalation_rules[{{ $index }}][active]" value="1" @checked((bool) old("escalation_rules.$index.active", $rule['active'] ?? false))>
                                        Active
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Regles temporelles sur echeance</h2>
                <p class="form-section-subtitle">Chaque regle se declenche a une distance exacte de l echeance. Exemple : <code>-3</code> pour J-3, <code>7</code> pour J+7. Variables supportees : <code>{action_label}</code>, <code>{level}</code>, <code>{message}</code>, <code>{offset_days}</code>.</p>
                <div class="space-y-4">
                    @foreach ($timelineRows as $index => $rule)
                        <div class="rounded-2xl border border-slate-200 bg-white/70 px-4 py-4 dark:border-slate-700 dark:bg-slate-900/40">
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <div>
                                    <label for="timeline_offset_{{ $index }}">Offset jours</label>
                                    <input id="timeline_offset_{{ $index }}" name="timeline_rules[{{ $index }}][offset_days]" type="number" min="-365" max="365" value="{{ old("timeline_rules.$index.offset_days", $rule['offset_days'] ?? 0) }}">
                                </div>
                                <div>
                                    <label for="timeline_level_{{ $index }}">Niveau</label>
                                    <select id="timeline_level_{{ $index }}" name="timeline_rules[{{ $index }}][level]">
                                        @foreach ($alertLevels as $levelCode => $definition)
                                            <option value="{{ $levelCode }}" @selected(old("timeline_rules.$index.level", $rule['level'] ?? 'warning') === $levelCode)>{{ $definition['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="timeline_target_{{ $index }}">Destinataire</label>
                                    <select id="timeline_target_{{ $index }}" name="timeline_rules[{{ $index }}][target_role]">
                                        <option value="">Aucun</option>
                                        @foreach ($ruleTargetOptions as $roleCode => $roleLabel)
                                            <option value="{{ $roleCode }}" @selected(old("timeline_rules.$index.target_role", $rule['target_role'] ?? '') === $roleCode)>{{ $roleLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="xl:col-span-4">
                                    <label for="timeline_message_{{ $index }}">Message template</label>
                                    <input id="timeline_message_{{ $index }}" name="timeline_rules[{{ $index }}][message_template]" type="text" value="{{ old("timeline_rules.$index.message_template", $rule['message_template'] ?? '') }}" placeholder="Ex. J-3 sur {action_label} : vigilance immediate">
                                </div>
                                <div class="md:col-span-2 xl:col-span-4 flex items-end gap-3">
                                    <label class="checkbox-pill !mb-0">
                                        <input type="checkbox" name="timeline_rules[{{ $index }}][active]" value="1" @checked((bool) old("timeline_rules.$index.active", $rule['active'] ?? false))>
                                        Active
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Enregistrer les politiques</button>
            </div>
        </form>
    </section>
@endsection

