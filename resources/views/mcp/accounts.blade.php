@extends('layouts.console')
@section('title', 'Choose read-only account')
@section('nav', 'connection')
@section('eyebrow', 'Brokerage · read only')
@section('heading', 'Choose read-only account')

@section('content')
<div class="page narrow">
    <x-callout tone="info" icon="shield">Agentic accounts can be bound for monitoring. Personal accounts provide advisory context only. This does not enable trading.</x-callout>
    <x-panel title="Account binding" subtitle="Only the last four characters of each account are shown." icon="wallet">
        <form method="post" action="{{ route('mcp.bind') }}" class="stack">@csrf
            <label class="field">Account<select name="selection" required>@foreach ($choices as $choice)<option value="{{ $choice['selection'] }}">{{ $choice['label'] }} ({{ $choice['kind'] }})</option>@endforeach</select></label>
            <label class="field">Role<select name="role"><option value="agentic_monitoring">Agentic monitoring</option><option value="personal_advisory">Personal advisory</option></select></label>
            <div class="form-actions"><a class="btn btn-ghost" href="{{ route('mcp.connection') }}">Back to connection</a><button class="btn btn-primary" type="submit"><x-icon name="check" :size="16" />Bind selected account</button></div>
        </form>
    </x-panel>
</div>
@endsection
