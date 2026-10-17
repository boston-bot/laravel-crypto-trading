@props(['tone' => 'info', 'icon' => null, 'title' => null, 'dismissible' => false])
@php $icon ??= ['ok' => 'check', 'warn' => 'alert', 'bad' => 'alert', 'neutral' => 'info', 'info' => 'info'][$tone] ?? 'info'; @endphp
<div {{ $attributes->merge(['class' => 'callout callout-'.$tone]) }}>
<span class="callout-icon"><x-icon :name="$icon" :size="18" /></span>
<div class="callout-body">@if($title)<strong>{{ $title }}</strong>@endif<div>{{ $slot }}</div></div>
@if($dismissible)<button type="button" class="icon-btn" data-dismiss aria-label="Dismiss"><x-icon name="x" :size="16" /></button>@endif
</div>
