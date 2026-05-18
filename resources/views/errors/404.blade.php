@include('errors.minimal', [
    'code' => '404',
    'title' => 'Page introuvable',
    'message' => "La page demandée n'existe pas ou a été déplacée. Vérifiez l'adresse ou retournez à l'accueil.",
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>',
])
