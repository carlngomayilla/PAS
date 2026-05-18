@include('errors.minimal', [
    'code' => '419',
    'title' => 'Session expirée',
    'message' => "Votre session a expiré pour des raisons de sécurité. Veuillez rafraîchir la page et réessayer.",
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
])
