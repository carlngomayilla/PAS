@php
    $pdfMode = (bool) ($pdfMode ?? false);
    $panelClass = $pdfMode ? 'pta-quarterly-preview pta-quarterly-preview-pdf' : 'mt-4 rounded-lg border border-[#d8ecf8] bg-white p-4';
@endphp

<section class="{{ $panelClass }}">
    <div class="{{ $pdfMode ? 'preview-heading' : 'flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between' }}">
        <div>
            <h2 class="{{ $pdfMode ? '' : 'text-base font-extrabold text-[#1c203d]' }}">{{ $wordPreview['title'] }}</h2>
            <p class="{{ $pdfMode ? '' : 'text-sm text-slate-500' }}">
                Periode : {{ $wordPreview['period']['label'] }} - cloture au {{ $wordPreview['period']['end_label'] }}
            </p>
            @if (! empty($wordPreview['template']))
                <p class="{{ $pdfMode ? '' : 'mt-1 text-xs font-semibold text-slate-500' }}">Modele : {{ $wordPreview['template'] }}</p>
            @endif
        </div>
        @unless ($pdfMode)
            <span class="rounded-full border border-[#d8ecf8] bg-[#f4fbff] px-3 py-1 text-xs font-bold uppercase text-[#0f5b66]">Word PTA</span>
        @endunless
    </div>

    <div class="{{ $pdfMode ? 'preview-cards' : 'mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4' }}">
        @foreach ($wordPreview['cards'] as $card)
            <div class="{{ $pdfMode ? 'preview-card' : 'rounded-lg border border-slate-200 bg-slate-50 p-3' }}">
                <p class="{{ $pdfMode ? '' : 'text-xs font-bold uppercase text-slate-500' }}">{{ $card['label'] }}</p>
                <p class="{{ $pdfMode ? '' : 'mt-2 text-xl font-extrabold text-[#1c203d]' }}">{{ $card['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="{{ $pdfMode ? 'preview-block' : 'mt-5' }}">
        <h3 class="{{ $pdfMode ? '' : 'text-sm font-extrabold uppercase text-slate-600' }}">Tableaux du document Word</h3>
        <div class="{{ $pdfMode ? '' : 'mt-3 space-y-4' }}">
            @foreach ($wordPreview['tables'] as $table)
                <article class="{{ $pdfMode ? 'preview-table-card' : 'rounded-lg border border-slate-200' }}">
                    <div class="{{ $pdfMode ? 'preview-table-title' : 'border-b border-slate-200 bg-slate-50 px-3 py-2' }}">
                        <h4 class="{{ $pdfMode ? '' : 'text-sm font-bold text-[#1c203d]' }}">{{ $table['title'] }}</h4>
                    </div>
                    <div class="{{ $pdfMode ? '' : 'overflow-x-auto' }}">
                        <table class="{{ $pdfMode ? 'preview-table' : 'min-w-full table-auto divide-y divide-slate-200 text-sm' }}">
                            <thead>
                                <tr>
                                    @foreach ($table['headers'] as $header)
                                        <th>{{ $header }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($table['rows'] as $row)
                                    <tr>
                                        @foreach ($row as $cell)
                                            <td>{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($table['headers']) }}">Aucune donnee disponible.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if ($table['footer'] !== null)
                                <tfoot>
                                    <tr>
                                        @foreach ($table['footer'] as $cell)
                                            <td>{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </article>
            @endforeach
        </div>
    </div>

    <div class="{{ $pdfMode ? 'preview-block' : 'mt-5' }}">
        <h3 class="{{ $pdfMode ? '' : 'text-sm font-extrabold uppercase text-slate-600' }}">Graphiques du document Word</h3>
        <div class="{{ $pdfMode ? 'preview-charts' : 'mt-3 grid gap-4 lg:grid-cols-3' }}">
            @foreach ($wordPreview['charts'] as $chart)
                <article class="{{ $pdfMode ? 'preview-chart' : 'rounded-lg border border-slate-200 p-3' }}">
                    <h4 class="{{ $pdfMode ? '' : 'text-sm font-bold text-[#1c203d]' }}">{{ $chart['title'] }}</h4>
                    <div class="{{ $pdfMode ? '' : 'mt-3 space-y-3' }}">
                        @forelse ($chart['points'] as $point)
                            <div class="{{ $pdfMode ? 'preview-bar-row' : 'grid gap-1' }}">
                                <div class="{{ $pdfMode ? 'preview-bar-label' : 'flex items-center justify-between gap-3 text-xs' }}">
                                    <span class="{{ $pdfMode ? '' : 'font-semibold text-slate-600' }}">{{ $point['label'] }}</span>
                                    <span class="{{ $pdfMode ? '' : 'font-bold text-[#0f5b66]' }}">{{ $point['display'] }}</span>
                                </div>
                                @if ($pdfMode)
                                    <div class="preview-bar-track">
                                        <div class="preview-bar-fill" style="width: {{ $point['width'] }}%;"></div>
                                    </div>
                                @else
                                    <progress class="h-2 w-full accent-[#0ea5d7]" value="{{ $point['width'] }}" max="100">{{ $point['display'] }}</progress>
                                @endif
                            </div>
                        @empty
                            <p class="{{ $pdfMode ? '' : 'text-sm text-slate-500' }}">Aucune donnee graphique disponible.</p>
                        @endforelse
                    </div>
                </article>
            @endforeach
        </div>
    </div>

    <div class="{{ $pdfMode ? 'preview-block' : 'mt-5' }}">
        <h3 class="{{ $pdfMode ? '' : 'text-sm font-extrabold uppercase text-slate-600' }}">Ecarts repris dans le modele Word</h3>
        <div class="{{ $pdfMode ? 'preview-gaps' : 'mt-3 grid gap-4 lg:grid-cols-3' }}">
            @foreach ($wordPreview['gap_sections'] as $section)
                <article class="{{ $pdfMode ? 'preview-gap' : 'rounded-lg border border-slate-200 p-3' }}">
                    <h4 class="{{ $pdfMode ? '' : 'text-sm font-bold text-[#1c203d]' }}">{{ $section['title'] }}</h4>
                    <div class="{{ $pdfMode ? '' : 'mt-3 space-y-2' }}">
                        @forelse ($section['rows'] as $row)
                            <div class="{{ $pdfMode ? 'preview-gap-item' : 'rounded-lg bg-slate-50 p-3 text-sm text-slate-700' }}">
                                <p class="{{ $pdfMode ? '' : 'font-bold text-[#1c203d]' }}">{{ $row['libelle'] }}</p>
                                <p class="{{ $pdfMode ? '' : 'mt-1 text-xs text-slate-500' }}">RMO : {{ $row['responsable'] }} - Fin : {{ $row['date_fin'] }} - Statut : {{ $row['statut'] }}</p>
                            </div>
                        @empty
                            <p class="{{ $pdfMode ? '' : 'text-sm text-slate-500' }}">Aucun ecart dans cette categorie.</p>
                        @endforelse
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
