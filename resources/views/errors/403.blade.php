@include('errors.minimal', [
    'code' => '403',
    'title' => 'Accès refusé',
    'message' => (isset($exception) && $exception?->getMessage())
        ? $exception->getMessage()
        : "Vous n'avez pas les autorisations nécessaires pour accéder à cette ressource. Contactez un administrateur si vous pensez qu'il s'agit d'une erreur.",
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
])
