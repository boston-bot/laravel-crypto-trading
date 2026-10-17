@props(['label', 'icon' => null, 'hint' => null])
<section {{ $attributes->merge(['class' => 'stat']) }}>
<div class="stat-label">@if($icon)<x-icon :name="$icon" :size="15" />@endif<span>{{ $label }}</span></div>
<div class="stat-value">{{ $slot }}</div>
@if($hint)<p class="stat-hint">{{ $hint }}</p>@endif
</section>
