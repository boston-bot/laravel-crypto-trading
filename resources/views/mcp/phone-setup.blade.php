<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Phone alert setup · Investing Operations</title>
<style>:root{font-family:system-ui,sans-serif;color-scheme:light dark}body{max-width:46rem;margin:3rem auto;padding:0 1rem;line-height:1.6}input{display:block;width:100%;box-sizing:border-box;font:inherit;padding:.6rem}label{display:block;margin-top:1rem}.note{border:1px solid #888;padding:1rem;border-radius:.4rem}button{font:inherit;padding:.6rem}</style></head><body>
<h1>Connect phone alerts</h1>
<p>Install the <a href="https://docs.ntfy.sh/subscribe/phone/" target="_blank" rel="noreferrer noopener">official ntfy app for iPhone or Android</a>. Add a subscription using these values:</p>
<label>Server<input readonly value="{{ $server }}" aria-label="ntfy server"></label>
<label>Topic<input readonly value="{{ $topic }}" aria-label="ntfy topic" spellcheck="false" autocomplete="off"></label>
<p>Select the topic and copy it into ntfy. This screen does not subscribe your phone or send a notification.</p>
<p class="note">Keep this topic private. On a public ntfy server, anyone who knows it may read or publish messages. The app sends operational status only, without account numbers, holdings or balances.</p>
@if($authenticatedServer)<p>Your server also uses authentication. Configure the permitted account in the ntfy app; the server token is never displayed here.</p>@endif
<p>After subscribing, run <code>php artisan mcp:notify --test</code> in the app directory and confirm the message appears on your phone. Provider acceptance alone does not prove phone delivery.</p>
<p>Heartbeat monitoring is a separate step: sign into <a href="https://healthchecks.io/accounts/login/" target="_blank" rel="noreferrer noopener">Healthchecks.io</a>, create an “Investing Ops” check with a one-minute period and ten-minute grace, then save its ping URL as <code>MCP_HEALTHCHECKS_PING_URL</code> in the private <code>.env</code>. Do not paste it into chat.</p>
<p><a href="{{ route('mcp.dashboard', ['page' => 'health']) }}">Back to System Health</a></p>
</body></html>
