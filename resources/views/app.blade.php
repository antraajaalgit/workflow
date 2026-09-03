<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Karya — Agency Flow OS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}?v=23" />
</head>
<body>
  <div id="login" class="login">
    <div>
      <div class="login-card">
        <div class="brand"><img class="brand-logo" src="{{ asset('assets/images/antrajaal-logo.png') }}" alt="Antrajaal" /></div>
        <p class="login-sub">Sign in with the email and password provided for your account.</p>
        <form id="login-form" class="login-form">
          <div class="field"><label for="login-email">Email address</label><input id="login-email" type="email" autocomplete="email" required placeholder="you@company.com" /></div>
          <div class="field"><label for="login-password">Password</label><div class="password-input"><input id="login-password" type="password" autocomplete="current-password" required placeholder="Enter your password" /><button type="button" id="login-password-toggle" class="password-toggle" aria-label="Show password">Show</button></div></div>
          <p id="login-error" class="login-error hidden" role="alert"></p>
          <button id="login-submit" class="btn login-submit" type="submit">Sign in</button>
        </form>
      </div>
      <p class="login-foot">Laravel + MySQL build · data is stored on the server</p>
    </div>
  </div>

  <div id="app" class="app hidden">
    <aside class="sidebar">
      <div class="brand small"><img class="brand-logo" src="{{ asset('assets/images/antrajaal-logo.png') }}" alt="Antrajaal" /></div>
      <nav id="nav" class="nav"></nav>
      <div class="side-foot"><div id="whoami" class="whoami"></div><button id="logout" class="btn-ghost small">Sign out</button></div>
    </aside>
    <main class="main"><header class="topbar"><div id="page-title" class="page-title">Dashboard</div><div class="topbar-actions"><button id="bell" class="bell" title="Notification log">🔔<span id="bell-count" class="bell-count hidden">0</span></button></div></header><div id="view" class="view"></div></main>
  </div>

  <div id="notif-drawer" class="drawer hidden"><div class="drawer-head"><strong>Outbound messages</strong><span class="muted small">WhatsApp + Email (simulated)</span><button id="notif-close" class="btn-ghost small">Close</button></div><div id="notif-list" class="notif-list"></div></div>
  <div id="scrim" class="scrim hidden"></div>
  <div id="modal-host"></div>
  <script src="{{ asset('assets/js/store.js') }}?v=14"></script>
  <script src="{{ asset('assets/js/app.js') }}?v=51"></script>
</body>
</html>
