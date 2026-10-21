@extends('layouts.console')
@section('title', 'Historical experiments')
@section('nav', 'experiments')
@section('eyebrow', 'Strategy research · historical simulation')
@section('heading', 'Experiments')
@section('content')
<div class="page">
    <div class="section-head"><div><h2>Test a strategy, keep the evidence</h2><p>Queue a historical run, compare variants and inspect simulated trades. Runs continue after you close this page.</p></div></div>
    @if($errors->any())<x-callout tone="bad" title="Experiment not started"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-callout>@endif
    @if(!$enabled)<x-callout tone="warn">Historical runs are disabled in this checkout. Existing results remain available.</x-callout>@endif
    @if(!$ready)
        <x-callout tone="info" title="Connect an agentic account">Bind your agentic monitoring account before starting or viewing account-scoped experiments. <a href="{{ route('mcp.connection') }}">Open broker connection</a>.</x-callout>
    @else
    <x-panel title="New historical experiment" subtitle="Research only. Virtual balances and trades stay separate from the paper ledger and brokerage." icon="performance">
        <form method="post" action="{{ route('mcp.experiments.store') }}" class="stack" data-experiment-form>
            @csrf
            <input type="hidden" name="request_id" value="{{ old('request_id', (string) Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}">
            <input type="hidden" name="auth_generation" value="{{ $connection->auth_generation }}">
            <div class="form-grid experiment-form-grid">
                <label class="field span-4">Experiment name<input name="name" value="{{ old('name', $example['name']) }}" required maxlength="120"></label>
                <label class="field span-4">Question to test<textarea name="hypothesis" required maxlength="2000" rows="2">{{ old('hypothesis', $example['hypothesis']) }}</textarea></label>
                <label class="field span-2">Tickers<input name="symbols" value="{{ old('symbols', implode(', ', $example['symbols'])) }}" required maxlength="64"><small class="note">One or two uppercase tickers, separated by commas.</small></label>
                <label class="field span-2">Completed trading sessions<textarea name="sessions" required maxlength="500" rows="2">{{ old('sessions', implode("\n", $example['sessions'])) }}</textarea><small class="note">Two to twenty consecutive sessions, YYYY-MM-DD, one per line. Include every trading session.</small></label>
            </div>
            <div>
                <h4>Comparison variants</h4>
                <p class="note">Choose a baseline. Blank overrides inherit the current rules; risk limits and costs are fixed for the run. Capital changes are identified separately.</p>
            </div>
            @php
                $inputVariants = old('variants', $example['variants']);
                $selectedBaseline = old('baseline_index', array_search($example['baseline'], array_column($example['variants'], 'id'), true));
            @endphp
            <div class="stack" data-variant-list>
                @foreach($inputVariants as $index => $variant)
                    @include('mcp.experiments.variant', compact('index', 'variant', 'selectedBaseline'))
                @endforeach
            </div>
            <template data-variant-template>@include('mcp.experiments.variant', ['index' => '__INDEX__', 'variant' => [], 'selectedBaseline' => null])</template>
            <div><button type="button" class="btn btn-secondary btn-sm" data-add-variant hidden>Add variant</button><span class="note"> Up to eight variants. Completely blank rows are ignored.</span></div>
            <details><summary>Reuse a completed dataset</summary>
                <label class="field">Completed experiment ID (optional)<input name="dataset_experiment" value="{{ old('dataset_experiment', $example['dataset_experiment'] ?? '') }}" maxlength="36" placeholder="Leave blank to acquire new data"></label>
                <p class="note">Symbols and dates must match that completed experiment. The app reuses its frozen data and compares against its baseline.</p>
            </details>
            <div class="form-actions"><button class="btn btn-primary" type="submit" @disabled(!$enabled)>Queue historical experiment</button></div>
        </form>
    </x-panel>
    <x-panel title="Experiment history" subtitle="Current account authorization · newest first" icon="clock" flush>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Experiment</th><th>Status</th><th>Tickers / sessions</th><th>Variants</th><th>Started (Central)</th><th></th></tr></thead>
            <tbody>@forelse($runs as $run)
                @php $spec = $run->definition['spec']; @endphp
                <tr><td><a href="{{ route('mcp.experiments.show', ['experiment' => $run->experiment_id]) }}"><strong>{{ $spec['name'] }}</strong></a><small class="block muted">{{ $run->experiment_id }}</small></td>
                    <td><x-pill :value="$run->status" /></td><td>{{ implode(', ', $spec['symbols']) }}<small class="block muted">{{ $spec['sessions'][0] }} – {{ last($spec['sessions']) }}</small></td>
                    <td class="num">{{ count($spec['variants']) }}</td><td><x-time :at="$run->created_at" :relative="false" /></td>
                    <td><a class="btn btn-secondary btn-sm" href="{{ route('mcp.experiments.show', ['experiment' => $run->experiment_id]) }}">View results</a></td></tr>
            @empty<tr><td colspan="6" class="empty-cell">No experiments for this account authorization. Queue a run above to begin.</td></tr>@endforelse</tbody>
        </table></div>
        {{ $runs->links('mcp.pagination') }}
    </x-panel>
    @endif
</div>
@endsection
