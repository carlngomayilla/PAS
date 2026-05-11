@extends('layouts.admin')

@section('title', 'Tableau de bord')

@section('content')
    <div class="dealdeck-dashboard app-screen-flow">
        @include('partials.dashboard-analytics')
    </div>
@endsection
