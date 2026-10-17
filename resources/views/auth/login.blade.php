<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Local operator sign in</title></head>
<body><main><h1>Local operator sign in</h1>
@if ($errors->any())<p role="alert">Sign in failed. Check your credentials.</p>@endif
<form method="post" action="/login">@csrf
<label>Email <input name="email" type="email" autocomplete="username" required></label>
<label>Password <input name="password" type="password" autocomplete="current-password" required></label>
<button type="submit">Sign in</button></form></main></body></html>
