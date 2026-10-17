@props(['value' => null, 'tone' => null])
<span {{ $attributes->merge(['class' => 'pill pill-'.($tone ?? App\Support\Mcp\DisplayFormat::tone($value))]) }}><i aria-hidden="true"></i>{{ $slot->isEmpty() ? $value : $slot }}</span>
