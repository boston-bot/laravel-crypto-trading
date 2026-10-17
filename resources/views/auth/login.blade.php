<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="referrer" content="no-referrer">
<title>Local operator sign in · Investing Operations</title>
<script>try{var t=localStorage.getItem('ops-theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}</script>
@vite(['resources/css/console.css', 'resources/js/console.js'])
</head>
<body class="auth-body">
<main class="auth">
    <div class="auth-card">
        <div class="brand brand-center">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 15v4M6 5v6M12 12v7M12 4v4M18 9v10M18 3v2"/><rect x="4.5" y="10" width="3" height="5" rx="1" fill="currentColor"/><rect x="10.5" y="7.5" width="3" height="5" rx="1" fill="currentColor"/><rect x="16.5" y="5" width="3" height="5" rx="1" fill="currentColor"/></svg></span>
            <span class="brand-text"><strong>Investing Ops</strong><small>Local operator console</small></span>
        </div>
        <h1>Local operator sign in</h1>
        <p class="muted">Provisioned operators only. There is no public registration.</p>
        @if ($errors->any())<x-callout tone="bad" role="alert">Sign in failed. Check your credentials.</x-callout>@endif
        <form method="post" action="/login" class="stack">@csrf
            <label class="field">Email<input name="email" type="email" autocomplete="username" required autofocus value="{{ old('email') }}"></label>
            <label class="field">Password<input name="password" type="password" autocomplete="current-password" required></label>
            <button class="btn btn-primary btn-block" type="submit">Sign in</button>
        </form>
        <p class="auth-foot"><x-icon name="lock" :size="13" />Read-only monitoring · execution disabled by default</p>
    </div>
</main>
</body>
</html>
