@extends('layouts.admin')

@php
    $routeName = request()->route()?->getName() ?? '';
    $workspaceTitle = $title ?? match (true) {
        str_starts_with($routeName, 'workspace.pas.') => 'Pilotage stratégique',
        str_starts_with($routeName, 'workspace.pao.') => "Plan d'actions opérationnel",
        str_starts_with($routeName, 'workspace.pta.') => 'Plan de travail annuel',
        str_starts_with($routeName, 'workspace.actions.financements') => 'Financements DAF',
        str_starts_with($routeName, 'workspace.actions.suivi') => 'Suivi des actions',
        str_starts_with($routeName, 'workspace.actions.') => 'Suivi des actions',
        str_starts_with($routeName, 'workspace.imports.') => 'Imports Excel',
        str_starts_with($routeName, 'workspace.kpi-mesures.') => 'Mesures des indicateurs',
        str_starts_with($routeName, 'workspace.kpi.') => 'Indicateurs de performance',
        str_starts_with($routeName, 'workspace.messaging.') => 'Messagerie',
        str_starts_with($routeName, 'workspace.audit.') => 'Audit',
        str_starts_with($routeName, 'workspace.profile.') => 'Profil',
        str_starts_with($routeName, 'workspace.referentiel.directions.') => 'Directions',
        str_starts_with($routeName, 'workspace.referentiel.services.') => 'Services',
        str_starts_with($routeName, 'workspace.referentiel.utilisateurs.') => 'Utilisateurs',
        str_starts_with($routeName, 'workspace.search.') => 'Recherche',
        default => 'Plateforme PAS ANBG',
    };
@endphp

@section('title', $workspaceTitle)
