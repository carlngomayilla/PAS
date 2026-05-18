@include('errors.minimal', [
    'code' => '429',
    'title' => 'Trop de requêtes',
    'message' => "Vous avez effectué trop de tentatives en peu de temps. Patientez quelques instants avant de réessayer.",
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
])
