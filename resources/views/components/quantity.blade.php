@props(['value' => null, 'fallback' => 'Unknown'])
@if($value === null)<span {{ $attributes->merge(['class' => 'unknown']) }}>{{ $fallback }}</span>@else<span {{ $attributes->merge(['class' => 'mono num']) }} title="Exact: {{ $value }}">{{ App\Support\Mcp\DisplayFormat::quantity($value) }}</span>@endif
