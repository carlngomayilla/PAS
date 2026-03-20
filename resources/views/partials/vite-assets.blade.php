@vite(['resources/css/app.css', 'resources/js/app.js'])

<style>
    .ui-card {
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: rgba(255, 255, 255, 0.95);
        border-radius: 1rem;
        padding: 1rem;
        box-shadow: 0 14px 32px -22px rgba(15, 23, 42, 0.45);
        backdrop-filter: blur(4px);
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
        border-bottom: 1px solid rgba(226, 232, 240, 0.85);
        padding: 0.625rem 0.75rem;
        text-align: left;
        vertical-align: top;
    }

    th {
        background: rgba(248, 250, 252, 0.9);
        font-size: 0.6875rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: rgb(71, 85, 105);
    }

    tbody tr:hover td {
        background: rgba(248, 250, 252, 0.7);
    }

    .dark .ui-card {
        border-color: rgba(51, 65, 85, 0.85);
        background: rgba(15, 23, 42, 0.8);
        box-shadow: 0 22px 48px -30px rgba(0, 0, 0, 0.86);
    }

    .dark .admin-theme-scope main > section > h1:first-child,
    .dark .admin-theme-scope main > section > h2:first-child {
        color: rgb(241, 245, 249);
    }

    .dark th,
    .dark .admin-theme-scope th {
        border-bottom-color: rgba(51, 65, 85, 0.85);
        background: rgba(15, 23, 42, 0.9);
        color: rgb(203, 213, 225);
    }

    .dark td,
    .dark .admin-theme-scope td {
        border-bottom-color: rgba(51, 65, 85, 0.85);
        color: rgb(226, 232, 240);
    }

    .dark tbody tr:hover td,
    .dark .admin-theme-scope tbody tr:hover td {
        background: rgba(30, 41, 59, 0.6);
    }

    .form-shell {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    .form-section {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 1.25rem;
        padding: 1.25rem;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.96) 100%);
        box-shadow: 0 22px 42px -30px rgba(15, 23, 42, 0.42);
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
        color: rgb(71, 85, 105);
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
        border-top: 1px solid rgba(226, 232, 240, 0.85);
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
    }

    .field-hint {
        margin-top: 0.25rem;
        font-size: 0.75rem;
        color: rgb(100, 116, 139);
    }

    .checkbox-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        min-height: 46px;
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 1rem;
        background: #fff;
        padding: 0.5rem 0.75rem;
        font-size: 0.875rem;
    }

    .conditional-block {
        border: 1px dashed rgba(148, 163, 184, 0.9);
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.92);
        padding: 0.875rem;
    }

    .conditional-block.is-frozen {
        opacity: 0.72;
        background: rgba(241, 245, 249, 0.86);
        border-color: rgba(203, 213, 225, 0.9);
    }

    .dark .form-section {
        border-color: rgba(51, 65, 85, 0.85);
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.92) 0%, rgba(15, 23, 42, 0.82) 100%);
    }

    .dark .form-section-subtitle {
        color: rgb(203, 213, 225);
    }

    .dark .form-actions {
        border-top-color: rgba(51, 65, 85, 0.85);
    }

    .dark .field-hint {
        color: rgb(148, 163, 184);
    }

    .dark .checkbox-pill {
        border-color: rgba(71, 85, 105, 0.85);
        background: rgba(15, 23, 42, 0.9);
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
        border-color: rgba(71, 85, 105, 0.85);
        background: rgba(15, 23, 42, 0.82);
    }

    .dark .conditional-block.is-frozen {
        border-color: rgba(71, 85, 105, 0.7);
        background: rgba(30, 41, 59, 0.82);
    }

    .admin-theme-scope input:not([type='checkbox']):not([type='radio']),
    .admin-theme-scope select,
    .admin-theme-scope textarea {
        width: 100%;
        border-radius: 0.85rem;
        border: 1px solid rgba(203, 213, 225, 0.95);
        background-color: rgba(255, 255, 255, 0.98) !important;
        color: rgb(15, 23, 42) !important;
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
        background: linear-gradient(135deg, rgba(10, 20, 46, 0.96) 0%, rgba(18, 35, 72, 0.92) 100%) !important;
        border-color: rgba(255, 255, 255, 0.10) !important;
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
            linear-gradient(135deg, rgba(10, 20, 46, 0.96) 0%, rgba(18, 35, 72, 0.92) 100%) !important;
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
        background-color: rgba(241, 245, 249, 0.95) !important;
        color: rgb(100, 116, 139) !important;
        border-color: rgba(203, 213, 225, 0.9) !important;
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
