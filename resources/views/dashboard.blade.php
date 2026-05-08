@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <div class="dealdeck-dashboard app-screen-flow">
        @include('partials.dashboard-analytics')
    </div>
@endsection
