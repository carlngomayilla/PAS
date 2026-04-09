<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&family=Source+Serif+4:wght@600;700&family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@vite(['resources/css/app.css', 'resources/js/app.js'])

<style>
    :root {
        {{ $appearanceSettings->cssVariablesInline() }};
    }

    html,
    body {
        font-family: var(--app-font-family);
        color: var(--app-text-color);
    }

    h1,
    h2,
    h3,
    .showcase-title,
    .form-section-title,
    .showcase-panel-title {
        font-family: var(--app-heading-font-family);
        letter-spacing: -0.02em;
    }

    .ui-card {
        border: 1px solid rgb(var(--app-border-color-rgb) / 0.9);
        background: var(--app-card-surface-light);
        border-radius: var(--app-card-radius);
        padding: 1rem;
        box-shadow: var(--app-card-shadow);
        backdrop-filter: blur(var(--app-card-blur));
    }

    .ui-card-lg {
        padding: 1.5rem;
    }

    .admin-theme-scope main > section > h1:first-child {
        margin-bottom: 0.5rem;
        font-size: 1.5rem;
        line-height: 1.9rem;
        font-weight: 600;
        letter-spacing: -0.01em;
    }

    .admin-theme-scope main > section > h2:first-child {
        margin-bottom: 0.75rem;
        font-size: 1.0625rem;
        line-height: 1.5rem;
        font-weight: 600;
    }

    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.875rem;
    }

    th,
    td {
        border-bottom: 1px solid rgb(var(--app-border-color-rgb) / 0.85);
        padding: 0.625rem 0.75rem;
        text-align: left;
        vertical-align: top;
    }

    th {
        background: var(--app-table-head-bg-light);
        font-size: 0.6875rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--app-muted-text-color);
    }

    tbody tr:hover td {
        background: var(--app-table-row-hover-light);
    }

    .dark .ui-card {
        border-color: rgb(var(--app-border-color-rgb) / 0.35);
        background: var(--app-card-surface-dark);
        box-shadow: var(--app-card-shadow-dark);
    }

    .dark .admin-theme-scope main > section > h1:first-child,
    .dark .admin-theme-scope main > section > h2:first-child {
        color: rgb(241, 245, 249);
    }

    .dark th,
    .dark .admin-theme-scope th {
        border-bottom-color: rgb(var(--app-border-color-rgb) / 0.35);
        background: var(--app-table-head-bg-dark);
        color: rgb(226 232 240);
    }

    .dark td,
    .dark .admin-theme-scope td {
        border-bottom-color: rgb(var(--app-border-color-rgb) / 0.35);
        color: rgb(226, 232, 240);
    }

    .dark tbody tr:hover td,
    .dark .admin-theme-scope tbody tr:hover td {
        background: var(--app-table-row-hover-dark);
    }

    .form-shell {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    .form-section {
        border: 1px solid rgb(var(--app-border-color-rgb) / 0.9);
        border-radius: 1.25rem;
        padding: 1.25rem;
        background: var(--app-form-surface-light);
        box-shadow: var(--app-card-shadow);
    }

    .form-section-title {
        margin: 0 0 0.25rem;
        font-size: 1.08rem;
        line-height: 1.5rem;
        font-weight: 600;
        letter-spacing: -0.01em;
    }

    .form-section-subtitle {
        margin: 0 0 1rem;
        font-size: 0.75rem;
        color: var(--app-muted-text-color);
    }

    .form-grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    }

    .form-grid-compact {
        display: grid;
        gap: 0.875rem;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    }

    .form-grid > div,
    .form-grid-compact > div {
        display: flex;
        flex-direction: column;
    }

    .form-actions {
        margin-top: 0.25rem;
        padding-top: 0.75rem;
        border-top: 1px solid rgb(var(--app-border-color-rgb) / 0.85);
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
    }

    .field-hint {
        margin-top: 0.25rem;
        font-size: 0.75rem;
        color: var(--app-muted-text-color);
    }

    .checkbox-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        min-height: 46px;
        border: 1px solid rgb(var(--app-border-color-rgb) / 0.95);
        border-radius: var(--app-card-radius);
        background: rgb(var(--app-card-background-rgb) / 0.98);
        padding: 0.5rem 0.75rem;
        font-size: 0.875rem;
    }

    .conditional-block {
        border: 1px dashed rgb(var(--app-border-color-rgb) / 0.9);
        border-radius: var(--app-card-radius);
        background: rgb(var(--app-card-background-rgb) / 0.92);
        padding: 0.875rem;
    }

    .btn {
        border-radius: var(--app-button-radius);
        box-shadow: 0 4px 14px rgb(var(--app-secondary-rgb) / 0.18);
    }

    .btn:hover {
        box-shadow: 0 8px 20px rgb(var(--app-primary-rgb) / 0.24);
    }

    .btn-primary,
    .btn-blue,
    .btn-green {
        background: var(--app-button-primary-bg) !important;
        box-shadow: 0 4px 14px rgb(var(--app-primary-rgb) / 0.18) !important;
        color: var(--app-button-primary-text) !important;
    }

    .btn-secondary {
        background: var(--app-button-secondary-bg) !important;
        box-shadow: 0 4px 14px rgb(var(--app-secondary-rgb) / 0.18) !important;
        color: var(--app-button-secondary-text) !important;
    }

    .btn-primary:hover,
    .btn-blue:hover,
    .btn-green:hover {
        background: var(--app-button-primary-bg-hover) !important;
        box-shadow: 0 8px 20px rgb(var(--app-primary-rgb) / 0.22) !important;
        color: var(--app-button-primary-text) !important;
    }

    .btn-secondary:hover {
        background: var(--app-button-secondary-bg-hover) !important;
        box-shadow: 0 8px 20px rgb(var(--app-secondary-rgb) / 0.22) !important;
        color: var(--app-button-secondary-text) !important;
    }

    .btn-red {
        background: var(--app-button-danger-bg) !important;
        box-shadow: 0 4px 14px rgb(var(--app-danger-rgb) / 0.24) !important;
        color: var(--app-button-danger-text) !important;
    }

    .btn-red:hover {
        background: var(--app-button-danger-bg-hover) !important;
        box-shadow: 0 8px 20px rgb(var(--app-danger-rgb) / 0.32) !important;
    }

    .btn-amber {
        background: var(--app-button-warning-bg) !important;
        color: var(--app-button-warning-text) !important;
        box-shadow: 0 4px 14px rgb(var(--app-warning-rgb) / 0.16) !important;
    }

    .btn-amber:hover {
        background: var(--app-button-warning-bg-hover) !important;
        box-shadow: 0 8px 20px rgb(var(--app-warning-rgb) / 0.3) !important;
    }

    .conditional-block.is-frozen {
        opacity: 0.72;
        background: rgba(241, 245, 249, 0.86);
        border-color: rgba(203, 213, 225, 0.9);
    }

    .dark .form-section {
        border-color: rgb(var(--app-border-color-rgb) / 0.35);
        background: var(--app-form-surface-dark);
    }

    .dark .form-section-subtitle {
        color: rgb(203, 213, 225);
    }

    .dark .form-actions {
        border-top-color: rgb(var(--app-border-color-rgb) / 0.35);
    }

    .dark .field-hint {
        color: rgb(148, 163, 184);
    }

    .dark .checkbox-pill {
        border-color: rgb(var(--app-border-color-rgb) / 0.35);
        background: rgb(var(--app-surface-rgb) / 0.9);
        color: rgb(226, 232, 240);
    }

    .dark input[type='checkbox'],
    .dark input[type='radio'] {
        border-color: rgba(255, 255, 255, 0.16);
        background: linear-gradient(135deg, rgba(10, 20, 46, 0.96) 0%, rgba(18, 35, 72, 0.92) 100%);
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.04),
            0 6px 16px -14px rgba(57, 150, 211, 0.35);
    }

    .dark .conditional-block {
        border-color: rgb(var(--app-border-color-rgb) / 0.35);
        background: rgb(var(--app-surface-rgb) / 0.82);
    }

    .dark .conditional-block.is-frozen {
        border-color: rgb(var(--app-border-color-rgb) / 0.3);
        background: rgba(30, 41, 59, 0.82);
    }

    .admin-theme-scope input:not([type='checkbox']):not([type='radio']),
    .admin-theme-scope select,
    .admin-theme-scope textarea {
        width: 100%;
        border-radius: var(--app-input-radius);
        border: 1px solid var(--app-input-border-color);
        background: var(--app-input-surface-light) !important;
        color: var(--app-text-color) !important;
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .admin-theme-scope select {
        appearance: none;
        padding-right: 2.8rem;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='m5 7 5 5 5-5' stroke='%231c203d' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.95rem center;
        background-size: 0.95rem;
    }

    .admin-theme-scope select[multiple] {
        min-height: 10.5rem;
        padding: 0.75rem;
        background-image: none !important;
        background-position: initial !important;
        background-size: auto !important;
    }

    .admin-theme-scope select[multiple] option {
        padding: 0.45rem 0.6rem;
        border-radius: 0.55rem;
    }

    .admin-theme-scope input:not([type='checkbox']):not([type='radio']) {
        min-height: 46px;
    }

    .admin-theme-scope textarea {
        min-height: 128px;
        resize: vertical;
    }

    .admin-theme-scope input[type='file'] {
        min-height: 46px;
        padding: 0.42rem 0.6rem;
    }

    .admin-theme-scope input[type='file']::file-selector-button {
        margin-right: 0.75rem;
        border: 0;
        border-radius: 0.7rem;
        padding: 0.4rem 0.75rem;
        background: rgba(15, 23, 42, 0.9);
        color: rgb(248, 250, 252);
        font-weight: 600;
        cursor: pointer;
    }

    .dark .admin-theme-scope input:not([type='checkbox']):not([type='radio']),
    .dark .admin-theme-scope select,
    .dark .admin-theme-scope textarea {
        background: var(--app-input-surface-dark) !important;
        border-color: rgb(var(--app-border-color-rgb) / 0.35) !important;
        color: rgb(241, 245, 249) !important;
        caret-color: rgb(248, 250, 252);
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.03),
            0 10px 24px -24px rgba(57, 150, 211, 0.42) !important;
    }

    .dark .admin-theme-scope select,
    .dark select {
        background-image:
            url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='m5 7 5 5 5-5' stroke='%23cbd5e1' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"),
            var(--app-input-surface-dark) !important;
        background-repeat: no-repeat, repeat !important;
        background-position: right 0.95rem center, center !important;
        background-size: 0.95rem, 100% 100% !important;
    }

    .dark .admin-theme-scope select[multiple],
    .dark select[multiple] {
        background-image: none !important;
    }

    .admin-theme-scope input::placeholder,
    .admin-theme-scope textarea::placeholder {
        color: rgb(148, 163, 184) !important;
        opacity: 1;
    }

    .dark .admin-theme-scope input::placeholder,
    .dark .admin-theme-scope textarea::placeholder,
    .dark input::placeholder,
    .dark textarea::placeholder {
        color: rgb(148, 163, 184) !important;
        opacity: 1;
    }

    .admin-theme-scope input:not([type='checkbox']):not([type='radio']):focus,
    .admin-theme-scope select:focus,
    .admin-theme-scope textarea:focus {
        border-color: rgba(14, 116, 144, 0.8) !important;
        box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.18) !important;
        outline: none !important;
    }

    .dark .admin-theme-scope input:not([type='checkbox']):not([type='radio']):focus,
    .dark .admin-theme-scope select:focus,
    .dark .admin-theme-scope textarea:focus,
    .dark input:not([type='checkbox']):not([type='radio']):focus,
    .dark select:focus,
    .dark textarea:focus {
        border-color: rgba(57, 150, 211, 0.55) !important;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.04),
            0 0 0 3px rgba(57, 150, 211, 0.30),
            0 16px 28px -24px rgba(57, 150, 211, 0.58) !important;
        outline: none !important;
    }

    .admin-theme-scope input:disabled,
    .admin-theme-scope select:disabled,
    .admin-theme-scope textarea:disabled {
        background-color: rgb(var(--app-card-background-rgb) / 0.7) !important;
        color: var(--app-muted-text-color) !important;
        border-color: rgb(var(--app-border-color-rgb) / 0.8) !important;
    }

    .dark .admin-theme-scope input:disabled,
    .dark .admin-theme-scope select:disabled,
    .dark .admin-theme-scope textarea:disabled,
    .dark input:disabled,
    .dark select:disabled,
    .dark textarea:disabled {
        background: linear-gradient(135deg, rgba(17, 24, 39, 0.88) 0%, rgba(30, 41, 59, 0.8) 100%) !important;
        color: rgb(148, 163, 184) !important;
        border-color: rgba(71, 85, 105, 0.55) !important;
        box-shadow: none !important;
    }

    .dark .admin-theme-scope input[type='file'] {
        background: linear-gradient(135deg, rgba(10, 20, 46, 0.96) 0%, rgba(18, 35, 72, 0.92) 100%) !important;
        border-color: rgba(255, 255, 255, 0.10) !important;
        color: rgb(241, 245, 249) !important;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.03),
            0 10px 24px -24px rgba(57, 150, 211, 0.42) !important;
    }

    .dark .admin-theme-scope input[type='file']::file-selector-button {
        background: linear-gradient(135deg, rgba(57, 150, 211, 0.95) 0%, rgba(28, 32, 61, 0.98) 100%);
        color: rgb(240, 249, 255);
        box-shadow: 0 8px 20px -18px rgba(57, 150, 211, 0.72);
    }

    .admin-theme-scope select option,
    .admin-theme-scope select optgroup {
        background-color: rgb(255, 255, 255);
        color: rgb(15, 23, 42);
    }

    .dark .admin-theme-scope select option,
    .dark .admin-theme-scope select optgroup,
    .dark select option,
    .dark select optgroup {
        background-color: rgb(15, 23, 42);
        color: rgb(241, 245, 249);
    }

    .admin-theme-scope input:-webkit-autofill,
    .admin-theme-scope input:-webkit-autofill:hover,
    .admin-theme-scope input:-webkit-autofill:focus,
    .admin-theme-scope textarea:-webkit-autofill,
    .admin-theme-scope select:-webkit-autofill {
        -webkit-text-fill-color: rgb(15, 23, 42) !important;
        -webkit-box-shadow: 0 0 0 1000px rgba(255, 255, 255, 0.98) inset !important;
        transition: background-color 5000s ease-in-out 0s;
    }

    .dark .admin-theme-scope input:-webkit-autofill,
    .dark .admin-theme-scope input:-webkit-autofill:hover,
    .dark .admin-theme-scope input:-webkit-autofill:focus,
    .dark .admin-theme-scope textarea:-webkit-autofill,
    .dark .admin-theme-scope select:-webkit-autofill,
    .dark input:-webkit-autofill,
    .dark input:-webkit-autofill:hover,
    .dark input:-webkit-autofill:focus,
    .dark textarea:-webkit-autofill,
    .dark select:-webkit-autofill {
        -webkit-text-fill-color: rgb(241, 245, 249) !important;
        -webkit-box-shadow: 0 0 0 1000px rgba(15, 23, 42, 0.92) inset !important;
        transition: background-color 5000s ease-in-out 0s;
    }

    /*
     * Light-mode accessibility override.
     * Ensures readable contrast even when prebuilt CSS dark variants are applied by media query.
     */
    html[data-theme='light'] .admin-theme-scope {
        color: rgb(15, 23, 42) !important;
        background: radial-gradient(circle at top, #f9fbff 0%, #f3f6fb 60%) !important;
    }

    html[data-theme='light'] .admin-theme-scope h1,
    html[data-theme='light'] .admin-theme-scope h2,
    html[data-theme='light'] .admin-theme-scope h3,
    html[data-theme='light'] .admin-theme-scope h4,
    html[data-theme='light'] .admin-theme-scope h5,
    html[data-theme='light'] .admin-theme-scope h6,
    html[data-theme='light'] .admin-theme-scope p,
    html[data-theme='light'] .admin-theme-scope span,
    html[data-theme='light'] .admin-theme-scope label,
    html[data-theme='light'] .admin-theme-scope td,
    html[data-theme='light'] .admin-theme-scope th,
    html[data-theme='light'] .admin-theme-scope a {
        color: rgb(15, 23, 42);
    }

    html[data-theme='light'] .admin-theme-scope .text-slate-900,
    html[data-theme='light'] .admin-theme-scope .text-slate-800,
    html[data-theme='light'] .admin-theme-scope .text-slate-700 {
        color: rgb(15, 23, 42) !important;
    }

    html[data-theme='light'] .admin-theme-scope .text-slate-600,
    html[data-theme='light'] .admin-theme-scope .text-slate-500,
    html[data-theme='light'] .admin-theme-scope .text-slate-400,
    html[data-theme='light'] .admin-theme-scope .text-slate-300,
    html[data-theme='light'] .admin-theme-scope .text-slate-200,
    html[data-theme='light'] .admin-theme-scope .text-slate-100 {
        color: rgb(51, 65, 85) !important;
    }

    html[data-theme='light'] .admin-theme-scope input:not([type='checkbox']):not([type='radio']),
    html[data-theme='light'] .admin-theme-scope select,
    html[data-theme='light'] .admin-theme-scope textarea {
        background-color: rgba(255, 255, 255, 0.98) !important;
        color: rgb(15, 23, 42) !important;
        border-color: rgba(148, 163, 184, 0.85) !important;
    }

    html[data-theme='light'] .admin-theme-scope input::placeholder,
    html[data-theme='light'] .admin-theme-scope textarea::placeholder {
        color: rgb(100, 116, 139) !important;
        opacity: 1 !important;
    }

    html[data-theme='light'] .admin-theme-scope [class*='dark\\:text-'] {
        color: inherit !important;
    }

    html[data-theme='light'] .admin-theme-scope .dark\:text-slate-100,
    html[data-theme='light'] .admin-theme-scope .dark\:text-slate-200,
    html[data-theme='light'] .admin-theme-scope .dark\:text-slate-300,
    html[data-theme='light'] .admin-theme-scope .dark\:text-slate-400,
    html[data-theme='light'] .admin-theme-scope .dark\:text-slate-500 {
        color: rgb(51, 65, 85) !important;
    }

    html[data-theme='light'] .admin-theme-scope .dark\:bg-slate-950,
    html[data-theme='light'] .admin-theme-scope .dark\:bg-slate-900,
    html[data-theme='light'] .admin-theme-scope .dark\:bg-slate-800,
    html[data-theme='light'] .admin-theme-scope .dark\:bg-slate-700 {
        background-color: rgba(248, 250, 252, 0.95) !important;
    }

    html[data-theme='light'] .admin-theme-scope .dark\:border-slate-900,
    html[data-theme='light'] .admin-theme-scope .dark\:border-slate-800,
    html[data-theme='light'] .admin-theme-scope .dark\:border-slate-700 {
        border-color: rgba(203, 213, 225, 0.9) !important;
    }
</style>
