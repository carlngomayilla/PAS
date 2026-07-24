@include('components.tables.pta-suivi-table', [
    'groups' => $groups,
    'rmoOptions' => $rmoOptions ?? [],
    'exportMode' => $exportMode ?? 'web',
])
