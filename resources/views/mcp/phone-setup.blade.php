@extends('layouts.console')
@section('title', 'Phone alert setup')
@section('nav', 'health')
@section('eyebrow', 'System health · alerts')
@section('heading', 'Connect phone alerts')

@section('content')
<div class="page narrow">
    <x-panel title="Subscribe in ntfy" icon="bell">
        <p>Install the <a href="https://docs.ntfy.sh/subscribe/phone/" target="_blank" rel="noreferrer noopener">official ntfy app for iPhone or Android</a>. Add a subscription using these values:</p>
        <div class="stack">
            <label class="field">Server<span class="copy-field"><input readonly value="{{ $server }}" aria-label="ntfy server" id="ntfy-server"><button type="button" class="icon-btn" data-copy-target="#ntfy-server" aria-label="Copy server"><x-icon name="copy" :size="15" /></button></span></label>
            <label class="field">Topic<span class="copy-field"><input readonly value="{{ $topic }}" aria-label="ntfy topic" spellcheck="false" autocomplete="off" id="ntfy-topic" class="mono"><button type="button" class="icon-btn" data-copy-target="#ntfy-topic" aria-label="Copy topic"><x-icon name="copy" :size="15" /></button></span></label>
        </div>
        <p class="note">Select the topic and copy it into ntfy. This screen does not subscribe your phone or send a notification.</p>
    </x-panel>
    <x-callout tone="warn" icon="lock" title="Keep this topic private">Keep this topic private. On a public ntfy server, anyone who knows it may read or publish messages. The app sends operational status only, without account numbers, holdings or balances.</x-callout>
    @if($authenticatedServer)<x-callout tone="info">Your server also uses authentication. Configure the permitted account in the ntfy app; the server token is never displayed here.</x-callout>@endif
    <x-panel title="Verify delivery" icon="check">
        <p>After subscribing, run <code>php artisan mcp:notify --test</code> in the app directory and confirm the message appears on your phone. Provider acceptance alone does not prove phone delivery.</p>
        <p>Heartbeat monitoring is a separate step: sign into <a href="https://healthchecks.io/accounts/login/" target="_blank" rel="noreferrer noopener">Healthchecks.io</a>, create an “Investing Ops” check with a one-minute period and ten-minute grace, then save its ping URL as <code>MCP_HEALTHCHECKS_PING_URL</code> in the private <code>.env</code>. Do not paste it into chat.</p>
    </x-panel>
    <p><a class="btn btn-ghost" href="{{ route('mcp.dashboard', ['page' => 'health']) }}"><x-icon name="chevron" :size="14" class="flip" />Back to System Health</a></p>
</div>
@endsection
