@extends('layouts.admin')

@section('title', $title ?? 'Modules metier - PAS')

@push('scripts')
    <script>
        function requireWorkflowReason(form, entityLabel) {
            var input = form.querySelector('input[name="motif_retour"]');
            if (!input) {
                return true;
            }

            var reason = window.prompt('Motif de retour brouillon (' + entityLabel + ') :');
            if (reason === null) {
                return false;
            }

            var trimmed = reason.trim();
            if (trimmed.length < 5) {
                window.alert('Veuillez saisir un motif de 5 caracteres minimum.');
                return false;
            }

            input.value = trimmed;
            return true;
        }
    </script>
@endpush
