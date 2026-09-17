@extends('layouts.app')

@section('page-title', 'Conversations')

@section('content')

@php
    $filtered = $search !== '' || !empty($selectedBots);
@endphp

<div class="page-head page-head-wide mb-4">
    <div>
        <h1>Conversations</h1>
        @if($multiWorkspace)
            <p>Every session recorded in the workspaces you can open, from embedded widgets and from the preview sandbox.</p>
        @else
            <p>Every session recorded in {{ $activeSystem->name }}, from embedded widgets and from the preview sandbox.</p>
        @endif
    </div>
</div>

@include('logs._list', [
    'listAction' => route('logs.index'),
    'listShowPicker' => true,
    'listFiltered' => $filtered,
    'listClearUrl' => route('logs.index', array_filter(['sort' => $sort, 'dir' => $dir, 'per_page' => $perPage === 25 ? null : $perPage])),
])

@include('logs._transcript')

@endsection
