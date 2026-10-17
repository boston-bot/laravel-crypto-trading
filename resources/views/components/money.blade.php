@props(['value' => null, 'fallback' => 'Unknown'])
@php $formatted = App\Support\Mcp\DisplayFormat::money($value); @endphp
@if($formatted === null)<span {{ $attributes->merge(['class' => 'unknown']) }}>{{ $fallback }}</span>@else<span {{ $attributes->merge(['class' => 'num']) }} title="Exact: {{ $value }}">{{ $formatted }}</span>@endif
