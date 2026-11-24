@props([
    'idPrefix',
    'label' => 'Je confirme apposer ma signature sur cette version du dossier.',
])

<div {{ $attributes->class(['mt-3 rounded-lg border border-sky-200 bg-sky-50/70 p-3 dark:border-sky-800 dark:bg-sky-950/30']) }}>
    <p class="text-sm font-bold text-slate-800 dark:text-slate-100">Signature électronique de validation</p>
    <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">
        Votre nom, votre fonction, la date et l’empreinte de votre signature seront figés dans la fiche PDF.
        <a class="font-bold text-sky-700 underline dark:text-sky-300" href="{{ route('workspace.profile.edit') }}#signature">Gérer ma signature</a>
    </p>
    <label for="{{ $idPrefix }}-signature-consent" class="mt-3 flex items-start gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
        <input id="{{ $idPrefix }}-signature-consent" name="signature_consent" type="checkbox" value="1" @checked(old('signature_consent'))>
        <span>{{ $label }}</span>
    </label>
    @error('signature_consent')
        <p class="field-error mt-1">{{ $message }}</p>
    @enderror
    <div class="mt-3">
        <label for="{{ $idPrefix }}-current-password">Confirmer avec mon mot de passe</label>
        <input id="{{ $idPrefix }}-current-password" name="current_password" type="password" autocomplete="current-password" inputmode="text">
        @error('current_password')
            <p class="field-error mt-1">{{ $message }}</p>
        @enderror
    </div>
</div>
