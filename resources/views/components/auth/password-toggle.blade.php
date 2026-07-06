@props([
    'target',
    'label' => 'Afficher le mot de passe',
    'pressedLabel' => 'Masquer le mot de passe',
])

<button
    type="button"
    data-password-toggle="{{ $target }}"
    data-password-show-label="{{ $label }}"
    data-password-hide-label="{{ $pressedLabel }}"
    aria-controls="{{ $target }}"
    aria-pressed="false"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    {{ $attributes->merge(['class' => 'auth-password-toggle']) }}
>
    <svg data-password-toggle-icon="show" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12s-3.75 6.75-9.75 6.75S2.25 12 2.25 12Z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
    </svg>
    <svg data-password-toggle-icon="hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" hidden>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M3 3l18 18" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M10.58 10.58A2 2 0 0 0 13.42 13.42" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M9.88 4.66A10.72 10.72 0 0 1 12 4.45c6 0 9.75 7.55 9.75 7.55a17.08 17.08 0 0 1-2.44 3.33" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6.23 6.85C3.73 8.48 2.25 12 2.25 12s3.75 7.55 9.75 7.55a10.4 10.4 0 0 0 4.13-.86" />
    </svg>
    <span class="auth-password-toggle-label" data-password-toggle-label>{{ $label }}</span>
</button>

@once
    <script @cspNonce>
        (function () {
            if (window.__anbgPasswordToggleBound) {
                return;
            }

            window.__anbgPasswordToggleBound = true;

            function syncButton(button, input, isVisible) {
                var showLabel = button.getAttribute('data-password-show-label') || 'Afficher le mot de passe';
                var hideLabel = button.getAttribute('data-password-hide-label') || 'Masquer le mot de passe';
                var label = isVisible ? hideLabel : showLabel;
                var showIcon = button.querySelector('[data-password-toggle-icon="show"]');
                var hideIcon = button.querySelector('[data-password-toggle-icon="hide"]');
                var labelNode = button.querySelector('[data-password-toggle-label]');

                button.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);

                if (labelNode) {
                    labelNode.textContent = label;
                }

                if (showIcon) {
                    showIcon.hidden = isVisible;
                }

                if (hideIcon) {
                    hideIcon.hidden = ! isVisible;
                }

                if (input) {
                    input.setAttribute('data-password-visible', isVisible ? '1' : '0');
                }
            }

            document.addEventListener('click', function (event) {
                var button = event.target.closest('[data-password-toggle]');
                if (! button) {
                    return;
                }

                var input = document.getElementById(button.getAttribute('data-password-toggle') || '');
                if (! input) {
                    return;
                }

                var isVisible = input.type === 'password';
                input.type = isVisible ? 'text' : 'password';
                syncButton(button, input, isVisible);

                try {
                    input.focus({ preventScroll: true });
                } catch (error) {
                    input.focus();
                }
            });

            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
                    var input = document.getElementById(button.getAttribute('data-password-toggle') || '');
                    syncButton(button, input, input ? input.type !== 'password' : false);
                });
            }, { once: true });
        })();
    </script>
@endonce
