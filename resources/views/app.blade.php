<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Nagare — Agency Flow OS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}?v=4" />
</head>
<body>
  <div id="login" class="login">
    <div>
      <div class="login-card">
        <div class="brand"><div class="brand-mark">流</div><div><h1>Nagare</h1><p class="tag">Agency Flow OS · by Antrajaal</p></div></div>
        <p class="login-sub">Choose how you want to sign in. (Demo accounts — no password needed.)</p>
        <div class="login-tabs"><button class="ltab active" data-side="team">Agency / Team</button><button class="ltab" data-side="client">Client</button></div>
        <div id="login-list" class="login-list"></div>
      </div>
      <p class="login-foot">Laravel + MySQL build · data is stored on the server</p>
    </div>
  </div>

  <div id="app" class="app hidden">
    <aside class="sidebar">
      <div class="brand small"><div class="brand-mark">流</div><div><h1>Nagare</h1></div></div>
      <nav id="nav" class="nav"></nav>
      <div class="side-foot"><div id="whoami" class="whoami"></div><button id="logout" class="btn-ghost small">Sign out</button></div>
    </aside>
    <main class="main"><header class="topbar"><div id="page-title" class="page-title">Dashboard</div><div class="topbar-actions"><button id="bell" class="bell" title="Notification log">🔔<span id="bell-count" class="bell-count hidden">0</span></button></div></header><div id="view" class="view"></div></main>
  </div>

  <div id="notif-drawer" class="drawer hidden"><div class="drawer-head"><strong>Outbound messages</strong><span class="muted small">WhatsApp + Email (simulated)</span><button id="notif-close" class="btn-ghost small">Close</button></div><div id="notif-list" class="notif-list"></div></div>
  <div id="scrim" class="scrim hidden"></div>
  <div id="modal-host"></div>
  <script src="{{ asset('assets/js/store.js') }}?v=5"></script>
  <script src="{{ asset('assets/js/app.js') }}?v=5"></script>
</body>
</html>
