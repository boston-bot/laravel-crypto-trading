@props(['value' => null, 'fallback' => 'Unknown', 'scale' => 2])
@php $formatted = App\Support\Mcp\DisplayFormat::money($value, $scale); @endphp
@if($formatted === null)<span {{ $attributes->merge(['class' => 'unknown']) }}>{{ $fallback }}</span>@else<span {{ $attributes->merge(['class' => 'num']) }} title="Exact: {{ $value }}">{{ $formatted }}</span>@endif
