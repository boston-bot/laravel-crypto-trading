@extends('layouts.console')
@section('title', $pages[$page])
@section('nav', $page)
@section('eyebrow', $page === 'control' ? 'Paper planning · simulation only' : 'Monitor · read only')
@section('heading', $pages[$page])

@section('content')
@if(session('monitor_status'))
    <x-callout tone="info" role="status" dismissible>{{ session('monitor_status') }}</x-callout>
@endif

@if(!$snapshot)
    <x-callout tone="warn" title="Holdings and balances unknown">No complete snapshot for this account. Holdings and balances are unknown. A failed or partial observation cannot replace the last complete snapshot.</x-callout>
@else
    <div class="snapshot-strip">
        <span class="live-dot" aria-hidden="true"></span>
        <span>Published <x-time :at="$snapshot->published_at" format="Y-m-d H:i:s T" /></span>
        <span class="divider" aria-hidden="true"></span>
        <span class="muted">Publication v{{ $snapshot->publication_version }}</span>
        <span class="divider" aria-hidden="true"></span>
        <span class="muted">Regular-equity holdings/orders coverage; other asset classes are not reconciled here.</span>
    </div>
    @if($runs->first()?->status === 'failed')
        <x-callout tone="bad" title="Observation failed">Latest observation failed: <code>{{ $runs->first()->error_code }}</code>. Prior complete holdings remain visible.</x-callout>
    @endif
@endif

<div class="page">
    @include('mcp.dashboard.'.$page)

    @include('mcp.dashboard.provenance')

    @if(in_array($page, ['overview', 'health'], true))
        @include('mcp.dashboard.incidents')
    @elseif($openIncidents > 0)
        <a class="callout callout-bad callout-link" href="{{ route('mcp.dashboard', ['page' => 'health']) }}#incidents">
            <span class="callout-icon"><x-icon name="bell" /></span>
            <span class="callout-body"><strong>{{ $openIncidents }} open {{ Illuminate\Support\Str::plural('incident', $openIncidents) }}</strong><span>Review and acknowledge in the incident inbox.</span></span>
            <x-icon name="chevron" />
        </a>
    @endif
</div>
@endsection
