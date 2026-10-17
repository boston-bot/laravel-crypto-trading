@props(['title' => null, 'subtitle' => null, 'icon' => null, 'flush' => false])
<section {{ $attributes->merge(['class' => 'panel'.($flush ? ' panel-flush' : '')]) }}>
@if($title || isset($actions))
<header class="panel-head">
<div class="panel-title">@if($icon)<span class="panel-icon"><x-icon :name="$icon" :size="16" /></span>@endif<div><h3>{{ $title }}</h3>@if($subtitle)<p>{{ $subtitle }}</p>@endif</div></div>
@isset($actions)<div class="panel-actions">{{ $actions }}</div>@endisset
</header>
@endif
<div class="panel-body">{{ $slot }}</div>
</section>
