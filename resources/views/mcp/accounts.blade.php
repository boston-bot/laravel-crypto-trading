<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Choose read-only account</title></head>
<body><main><h1>Choose read-only account</h1>
<p>Agentic accounts can be bound for monitoring. Personal accounts provide advisory context only. This does not enable trading.</p>
<form method="post" action="{{ route('mcp.bind') }}">@csrf
<label>Account <select name="selection" required>@foreach ($choices as $choice)<option value="{{ $choice['selection'] }}">{{ $choice['label'] }} ({{ $choice['kind'] }})</option>@endforeach</select></label>
<label>Role <select name="role"><option value="agentic_monitoring">Agentic monitoring</option><option value="personal_advisory">Personal advisory</option></select></label>
<button>Bind selected account</button></form><a href="{{ route('mcp.connection') }}">Back to connection</a>
</main></body></html>
