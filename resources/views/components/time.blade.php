@props(['at' => null, 'fallback' => 'Unknown', 'format' => 'M j, H:i:s T', 'relative' => true])
@php $time = App\Support\Mcp\DisplayFormat::time($at, $format); @endphp
@if($time)<time datetime="{{ $time['iso'] }}" title="{{ $time['iso'] }}" @if($relative) data-relative @endif {{ $attributes }}>{{ $time['local'] }}</time>@else<span class="unknown">{{ $fallback }}</span>@endif
