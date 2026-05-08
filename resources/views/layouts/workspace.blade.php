@extends('layouts.admin')

@php
    $routeName = request()->route()?->getName() ?? '';
    $workspaceTitle = $title ?? match (true) {
        str_starts_with($routeName, 'workspace.pas.') => 'PAS',
        str_starts_with($routeName, 'workspace.pao.') => 'PAO',
        str_starts_with($routeName, 'workspace.pta.') => 'PTA',
        str_starts_with($routeName, 'workspace.actions.financements') => 'Financements',
        str_starts_with($routeName, 'workspace.actions.suivi') => 'Suivi action',
        str_starts_with($routeName, 'workspace.actions.') => 'Actions',
        str_starts_with($routeName, 'workspace.kpi_mesures.') => 'Mesures KPI',
        str_starts_with($routeName, 'workspace.kpi.') => 'Indicateurs',
        str_starts_with($routeName, 'workspace.messaging.') => 'Messagerie',
        str_starts_with($routeName, 'workspace.audit.') => 'Audit',
        str_starts_with($routeName, 'workspace.profile.') => 'Profil',
        str_starts_with($routeName, 'workspace.referentiel.directions.') => 'Directions',
        str_starts_with($routeName, 'workspace.referentiel.services.') => 'Services',
        str_starts_with($routeName, 'workspace.referentiel.utilisateurs.') => 'Utilisateurs',
        str_starts_with($routeName, 'workspace.search.') => 'Recherche',
        default => 'Espace PAS',
    };
@endphp

@section('title', $workspaceTitle)
