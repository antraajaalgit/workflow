/* ============ NAGARE · App ============ */
const S = () => Store.data;
const $ = (s, r=document) => r.querySelector(s);
const $$ = (s, r=document) => [...r.querySelectorAll(s)];

let session = null;   // current signed-in user
let route = 'dashboard';
let routeParam = null;

/* ---------- utils ---------- */
const esc = (s='') => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const userById = id => S().users.find(u => u.id === id);
const clientById = id => S().clients.find(c => c.id === id);
const tasksOf = cid => S().tasks.filter(t => t.clientId === cid);
const initials = n => n.split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase();

function avatar(u, cls='') {
  if (!u) return '';
  return `<span class="avatar ${cls}" style="background:${u.color||'#7a5c3e'}">${initials(u.name)}</span>`;
}
function timeAgo(ts) {
  const d = Math.floor((Date.now()-ts)/60000);
  if (d < 1) return 'just now';
  if (d < 60) return d+'m ago';
  const h = Math.floor(d/60);
  if (h < 24) return h+'h ago';
  return Math.floor(h/24)+'d ago';
}
function clockTime(ts){ return new Date(ts).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}); }

/* ---------- ANDON ---------- */
const ACTIVE = ['new','todo','in_progress','review'];
function elapsedMs(t){ return Date.now() - t.stageAt; }
function andonLevel(t){
  if (!ACTIVE.includes(t.status)) return 'done';
  const m = elapsedMs(t)/60000;
  const {amberMin, redMin} = S().settings;
  if (m >= redMin) return 'red';
  if (m >= amberMin) return 'amber';
  return 'green';
}
function fmtElapsed(t){
  const s = Math.floor(elapsedMs(t)/1000);
  const m = Math.floor(s/60), sec = s%60;
  if (m < 60) return String(m).padStart(2,'0')+':'+String(sec).padStart(2,'0');
  const h = Math.floor(m/60);
  return h+'h '+String(m%60).padStart(2,'0')+'m';
}

/* ---------- NOTIFICATIONS (simulated WhatsApp + Email) ---------- */
const Notify = {
  fire(channel, to, text){
    S().notifications.unshift({ id:Math.random().toString(36).slice(2,9), channel, to, text, at:Date.now() });
    Store.save();
    toast(`${channel==='whatsapp'?'📱 WhatsApp':'✉️ Email'} sent → ${to}`, channel==='whatsapp'?'wa':'email');
    updateBell();
  },
  both({phone, email, waText, emailText}){
    if (phone) this.fire('whatsapp', phone, waText);
    if (email) this.fire('email', email, emailText || waText);
  }
};
function updateBell(){
  const n = S().notifications.length;
  const b = $('#bell-count');
  if (b){ b.textContent = n; b.classList.toggle('hidden', n===0); }
}
function logActivity(text, type='info'){
  S().activity.unshift({ id:Math.random().toString(36).slice(2,9), at:Date.now(), text, type });
  Store.save();
}

/* ---------- toast ---------- */
let toastWrap;
function toast(msg, cls=''){
  if (!toastWrap){ toastWrap = document.createElement('div'); toastWrap.className='toast-wrap'; document.body.appendChild(toastWrap); }
  const t = document.createElement('div'); t.className='toast '+cls; t.textContent = msg;
  toastWrap.appendChild(t);
  setTimeout(()=>{ t.style.opacity='0'; t.style.transition='.4s'; setTimeout(()=>t.remove(),400); }, 3200);
}

/* ---------- AUTO-DELEGATION ---------- */
function delegate(text){
  const lc = (text||'').toLowerCase();
  for (const rule of S().rules){
    for (const kw of rule.kw.split(',')){
      if (kw.trim() && lc.includes(kw.trim())) return rule.dept;
    }
  }
  return 'Design'; // fallback
}
function pickOwner(dept){
  const members = S().users.filter(u => u.role==='team' && u.dept===dept);
  if (!members.length) return null;
  // least-loaded (Heijunka): fewest active tasks
  members.sort((a,b)=> activeLoad(a.id) - activeLoad(b.id));
  return members[0].id;
}
function activeLoad(uid){ return S().tasks.filter(t=>t.ownerId===uid && ACTIVE.includes(t.status)).length; }

/* ============================================================
   AUTH
============================================================ */
function renderLogin(){
  const side = $('.ltab.active').dataset.side;
  const users = side==='team'
    ? S().users.filter(u => u.role==='admin' || u.role==='team')
    : S().users.filter(u => u.role==='client');
  $('#login-list').innerHTML = users.map(u => {
    const sub = u.role==='client' ? u.company : (u.role==='admin' ? 'Owner / Admin' : u.dept+' Dept');
    const pill = u.role==='admin' ? 'Admin' : u.role==='team' ? 'Team' : 'Client';
    return `<button class="user-pick" data-id="${u.id}">
      ${avatar(u)}
      <div class="meta"><b>${esc(u.name)}</b><span>${esc(sub)}</span></div>
      <span class="pill">${pill}</span>
    </button>`;
  }).join('');
  $$('.user-pick').forEach(b => b.onclick = () => signIn(b.dataset.id));
}
async function signIn(id){
  await Store.signIn(id);
  session = userById(id);
  $('#login').classList.add('hidden');
  $('#app').classList.remove('hidden');
  route = session.role==='client' ? 'my-requests' : 'dashboard';
  buildNav(); renderWho(); updateBell(); render();
}
async function signOut(){
  await Store.signOut();
  session = null;
  $('#app').classList.add('hidden');
  $('#login').classList.remove('hidden');
  renderLogin();
}
function renderWho(){
  $('#whoami').innerHTML = `${avatar(session)}<div><b>${esc(session.name)}</b><span>${session.role==='client'?esc(session.company):(session.dept||'Owner')}</span></div>`;
}

/* ============================================================
   NAV
============================================================ */
const NAV = {
  admin: [
    {id:'dashboard', ic:'📊', label:'Dashboard'},
    {id:'andon', ic:'🚦', label:'Andon Board'},
    {id:'kanban', ic:'🗂️', label:'Kanban'},
    {id:'clients', ic:'📁', label:'Client Folders'},
    {id:'inbox', ic:'💬', label:'Inbox'},
    {id:'recurring', ic:'🔁', label:'Recurring Tasks'},
    {sep:'Manage'},
    {id:'team', ic:'👥', label:'Team & Load'},
    {id:'settings', ic:'⚙️', label:'Settings'},
  ],
  team: [
    {id:'dashboard', ic:'📊', label:'My Dashboard'},
    {id:'kanban', ic:'🗂️', label:'Kanban'},
    {id:'andon', ic:'🚦', label:'Andon Board'},
    {id:'inbox', ic:'💬', label:'Inbox'},
    {id:'clients', ic:'📁', label:'Client Folders'},
  ],
  client: [
    {id:'my-requests', ic:'📋', label:'My Requests'},
    {id:'new-request', ic:'➕', label:'New Request'},
    {id:'messages', ic:'💬', label:'Messages'},
  ],
};
function buildNav(){
  const items = NAV[session.role];
  $('#nav').innerHTML = items.map(it => {
    if (it.sep) return `<div class="nav-sep">${it.sep}</div>`;
    let badge = '';
    if (it.id==='andon'){ const r = S().tasks.filter(t=>andonLevel(t)==='red').length; if (r) badge=`<span class="badge">${r}</span>`; }
    return `<a data-route="${it.id}" class="${route===it.id?'active':''}"><span class="ic">${it.ic}</span>${it.label}${badge}</a>`;
  }).join('');
  $$('#nav a').forEach(a => a.onclick = () => go(a.dataset.route));
}
function go(r, param=null){ route=r; routeParam=param; buildNav(); render(); }

/* ============================================================
   RENDER ROUTER
============================================================ */
const TITLES = {dashboard:'Dashboard', andon:'Andon Board', kanban:'Kanban Flow', clients:'Client Folders', inbox:'Inbox', recurring:'Recurring Tasks', team:'Team & Workload', settings:'Settings', 'my-requests':'My Requests', 'new-request':'New Request', messages:'Messages', 'client-folder':'Client Folder'};
function render(){
  $('#page-title').textContent = TITLES[route] || '';
  const v = $('#view');
  const R = {
    dashboard: viewDashboard, andon: viewAndon, kanban: viewKanban,
    clients: viewClients, 'client-folder': viewClientFolder, inbox: viewInbox,
    recurring: viewRecurring, team: viewTeam, settings: viewSettings,
    'my-requests': viewMyRequests, 'new-request': viewNewRequest, messages: viewMessages,
  };
  v.innerHTML = (R[route] || viewDashboard)();
  bindView();
}

/* ---------- DASHBOARD ---------- */
function viewDashboard(){
  const scope = session.role==='team' ? S().tasks.filter(t=>t.ownerId===session.id) : S().tasks;
  const active = scope.filter(t=>ACTIVE.includes(t.status));
  const red = scope.filter(t=>andonLevel(t)==='red');
  const amber = scope.filter(t=>andonLevel(t)==='amber');
  const doneToday = scope.filter(t=>t.status==='done').length;
  const overdue = scope.filter(t=>t.dueDate < Date.now() && t.status!=='done').length;

  const kpi = (val,lab,sub='',subcolor='') => `<div class="kpi"><div class="k-val">${val}</div><div class="k-lab">${lab}</div>${sub?`<div class="k-sub" style="color:${subcolor}">${sub}</div>`:''}</div>`;

  const redRows = red.length ? red.map(andonRow).join('') :
    `<div class="empty" style="padding:26px"><div class="e-ic">✅</div>No stuck tasks. Flow is smooth.</div>`;

  // workload
  const team = S().users.filter(u=>u.role==='team');
  const maxLoad = Math.max(1, ...team.map(u=>activeLoad(u.id)));
  const workload = team.map(u => `
    <div style="margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
        <span>${avatar(u,'')} ${esc(u.name)} <span class="muted small">· ${u.dept}</span></span>
        <b>${activeLoad(u.id)}</b>
      </div>
      <div class="bar"><i style="width:${activeLoad(u.id)/maxLoad*100}%;background:${activeLoad(u.id)>=4?'var(--red)':'var(--accent2)'}"></i></div>
    </div>`).join('');

  const feed = S().activity.slice(0,8).map(a=>`
    <div class="row-item" style="padding:10px 12px">
      <span style="font-size:16px">${a.type==='brief'?'📥':a.type==='msg'?'💬':a.type==='move'?'➡️':'•'}</span>
      <div style="flex:1"><div style="font-size:13px">${esc(a.text)}</div><div class="muted small">${timeAgo(a.at)}</div></div>
    </div>`).join('');

  return `
    <div class="grid cols-4" style="margin-bottom:22px">
      ${kpi(active.length, 'Active tasks')}
      ${kpi(red.length, 'Stuck (red) 🔴', red.length?'Needs attention':'All clear', red.length?'var(--red)':'var(--green)')}
      ${kpi(amber.length, 'Slowing (amber) 🟡')}
      ${kpi(overdue, 'Overdue', overdue?'Past due date':'On schedule', overdue?'var(--red)':'var(--green)')}
    </div>
    <div class="grid" style="grid-template-columns:1.4fr 1fr">
      <div class="card">
        <div class="section-head"><h2>🚦 Andon — needs attention now</h2><button class="btn-ghost small" data-go="andon">View all →</button></div>
        ${redRows}
      </div>
      <div style="display:flex;flex-direction:column;gap:16px">
        <div class="card"><h3>Team workload (Heijunka)</h3>${workload || '<span class="muted small">No team members</span>'}</div>
      </div>
    </div>
    <div class="card" style="margin-top:16px"><h3>Live activity (Gemba feed)</h3><div class="list">${feed||'<span class="muted">No activity yet</span>'}</div></div>
  `;
}

/* ---------- ANDON BOARD ---------- */
function andonRow(t){
  const lvl = andonLevel(t);
  const owner = userById(t.ownerId);
  const c = clientById(t.clientId);
  return `<div class="andon-row ${lvl}" data-task="${t.id}">
    <span class="light ${lvl}"></span>
    <div class="a-main"><b>${esc(t.title)}</b><span>${esc(c.company)} · ${t.dept} · ${owner?esc(owner.name):'Unassigned'}</span></div>
    <span class="status-pill st-${t.status}">${t.status.replace('_',' ')}</span>
    <span class="timer ${lvl==='green'?'':lvl}" data-timer="${t.id}">${fmtElapsed(t)}</span>
  </div>`;
}
function viewAndon(){
  const scope = session.role==='team' ? S().tasks.filter(t=>t.ownerId===session.id) : S().tasks;
  const active = scope.filter(t=>ACTIVE.includes(t.status))
    .sort((a,b)=> elapsedMs(b)-elapsedMs(a));
  const {amberMin, redMin} = S().settings;
  const groups = {red:[],amber:[],green:[]};
  active.forEach(t=>groups[andonLevel(t)].push(t));
  const sec = (title,arr,color)=> `
    <div class="card" style="margin-bottom:14px">
      <div class="section-head"><h2 style="color:${color}">${title} <span class="muted small" style="font-weight:500">(${arr.length})</span></h2></div>
      ${arr.length?arr.map(andonRow).join(''):'<span class="muted small">None</span>'}
    </div>`;
  return `
    <p class="muted" style="margin-bottom:16px">A task lights up by how long it has sat in its current stage. Thresholds: 🟡 after <b>${amberMin} min</b>, 🔴 after <b>${redMin} min</b> — change in Settings.</p>
    ${sec('🔴 Stuck — act now', groups.red, 'var(--red)')}
    ${sec('🟡 Slowing down', groups.amber, 'var(--amber)')}
    ${sec('🟢 On track', groups.green, 'var(--green)')}
  `;
}

/* ---------- KANBAN ---------- */
const COLS = [
  {id:'new', label:'New / Inbox'},
  {id:'todo', label:'To Do'},
  {id:'in_progress', label:'In Progress'},
  {id:'review', label:'Review'},
  {id:'done', label:'Done'},
];
const WIP = {in_progress:4, review:3};
function kcard(t){
  const owner = userById(t.ownerId);
  const dc = DEPT_COLOR[t.dept];
  const lvl = andonLevel(t);
  return `<div class="kcard" data-task="${t.id}">
    <div class="kc-top">
      <span class="dep" style="background:${dc.bg};color:${dc.fg}">${t.dept}</span>
      ${ACTIVE.includes(t.status)?`<span class="light ${lvl}" title="${fmtElapsed(t)}"></span>`:''}
      ${t.recurring?`<span title="repeats ${t.recurring}">🔁</span>`:''}
    </div>
    <b>${esc(t.title)}</b>
    <div class="kc-foot">
      ${owner?avatar(owner):'<span class="muted small">Unassigned</span>'}
      <span class="prio ${t.priority}">${t.priority}</span>
    </div>
  </div>`;
}
function viewKanban(){
  let scope = S().tasks;
  if (session.role==='team') scope = scope.filter(t=>t.ownerId===session.id || t.dept===session.dept);
  const cols = COLS.map(col=>{
    const items = scope.filter(t=>t.status===col.id);
    const wip = WIP[col.id];
    const over = wip && items.length>wip;
    return `<div class="kcol">
      <div class="kcol-head"><b>${col.label}</b><span class="wip ${over?'over':''}">${items.length}${wip?'/'+wip:''}</span></div>
      ${items.map(kcard).join('')||'<div class="muted small" style="padding:8px 4px">—</div>'}
    </div>`;
  }).join('');
  return `<p class="muted" style="margin-bottom:14px">Click a card to open it, reassign, chat, or move stage. WIP limits (red count) flag overload.</p><div class="kanban">${cols}</div>`;
}

/* ---------- CLIENT FOLDERS ---------- */
function viewClients(){
  const folders = S().clients.map(c=>{
    const ts = tasksOf(c.id);
    const active = ts.filter(t=>ACTIVE.includes(t.status)).length;
    const red = ts.filter(t=>andonLevel(t)==='red').length;
    return `<div class="folder" data-client="${c.id}">
      <div class="f-ic">📁</div>
      <b>${esc(c.company)}</b>
      <div class="f-meta">${esc(c.name)} · ${esc(c.email)}</div>
      <div class="f-stats">
        <div><b>${ts.length}</b><span class="muted">total</span></div>
        <div><b>${active}</b><span class="muted">active</span></div>
        <div><b style="color:${red?'var(--red)':''}">${red}</b><span class="muted">stuck</span></div>
      </div>
    </div>`;
  }).join('');
  return `<p class="muted" style="margin-bottom:16px">Every client has a dedicated folder — all their briefs, files, voice notes and the full conversation in one place.</p><div class="folder-grid">${folders}</div>`;
}
function viewClientFolder(){
  const c = clientById(routeParam);
  if (!c) return viewClients();
  const ts = tasksOf(c.id);
  const msgs = S().messages.filter(m=>m.clientId===c.id).sort((a,b)=>a.at-b.at);
  const taskList = ts.map(t=>{
    const owner=userById(t.ownerId); const lvl=andonLevel(t);
    return `<div class="row-item" data-task="${t.id}" style="cursor:pointer">
      ${ACTIVE.includes(t.status)?`<span class="light ${lvl}"></span>`:'<span style="width:11px"></span>'}
      <div style="flex:1"><b style="font-size:14px">${esc(t.title)}</b><div class="muted small">${t.dept} · ${owner?esc(owner.name):'—'}${t.recurring?' · 🔁 '+t.recurring:''}</div></div>
      <span class="status-pill st-${t.status}">${t.status.replace('_',' ')}</span>
    </div>`;
  }).join('');
  return `
    <div class="back-link" data-go="clients">← All clients</div>
    <div class="section-head">
      <div style="display:flex;align-items:center;gap:14px">${avatar(c)}<div><h2>${esc(c.company)}</h2><span class="muted small">${esc(c.name)} · ${esc(c.email)} · ${esc(c.phone)}</span></div></div>
    </div>
    <div class="grid" style="grid-template-columns:1fr 1fr">
      <div class="card"><h3>📋 Work & briefs (${ts.length})</h3><div class="list">${taskList||'<span class="muted">No tasks</span>'}</div></div>
      <div class="card">
        <h3>💬 Full conversation</h3>
        ${renderChat(msgs)}
        ${composerHTML(c.id, null)}
      </div>
    </div>`;
}

/* ---------- INBOX (all conversations) ---------- */
function viewInbox(){
  const byClient = S().clients.map(c=>{
    const msgs = S().messages.filter(m=>m.clientId===c.id);
    const last = msgs.sort((a,b)=>b.at-a.at)[0];
    return {c, last, count:msgs.length};
  }).filter(x=>x.last).sort((a,b)=>b.last.at-a.last.at);
  const list = byClient.map(({c,last})=>{
    const from = last.fromRole==='client'?c.name:'Team';
    return `<div class="row-item" data-client="${c.id}" style="cursor:pointer">
      ${avatar(c)}
      <div style="flex:1;min-width:0"><b style="font-size:14px">${esc(c.company)}</b>
        <div class="muted small" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(from)}: ${last.voice?'🎤 Voice note':esc(last.text)}</div></div>
      <span class="muted small">${timeAgo(last.at)}</span>
    </div>`;
  }).join('');
  return `<p class="muted" style="margin-bottom:16px">Every client conversation — bypassing WhatsApp. Click to open the thread & reply (text or voice).</p><div class="list">${list||'<span class="muted">No messages yet</span>'}</div>`;
}

/* ---------- RECURRING ---------- */
function viewRecurring(){
  const rec = S().tasks.filter(t=>t.recurring);
  const rows = rec.map(t=>{
    const c=clientById(t.clientId), owner=userById(t.ownerId);
    return `<div class="row-item">
      <span style="font-size:16px">🔁</span>
      <div style="flex:1"><b style="font-size:14px">${esc(t.title)}</b><div class="muted small">${esc(c.company)} · ${t.dept} · ${owner?esc(owner.name):'—'}</div></div>
      <span class="pill">${t.recurring}</span>
      <span class="status-pill st-${t.status}">${t.status.replace('_',' ')}</span>
    </div>`;
  }).join('');
  return `
    <div class="section-head"><h2>Repeating tasks</h2><button class="btn small" data-new-recurring>+ New repeating task</button></div>
    <p class="muted" style="margin-bottom:16px">These auto-create on schedule (daily / weekly / monthly) — e.g. weekly social posts, monthly reports.</p>
    <div class="list">${rows||'<div class="empty"><div class="e-ic">🔁</div>No repeating tasks yet</div>'}</div>`;
}

/* ---------- TEAM ---------- */
function viewTeam(){
  const team = S().users.filter(u=>u.role==='team');
  const rows = team.map(u=>{
    const load=activeLoad(u.id);
    const done=S().tasks.filter(t=>t.ownerId===u.id&&t.status==='done').length;
    const red=S().tasks.filter(t=>t.ownerId===u.id&&andonLevel(t)==='red').length;
    return `<div class="row-item">
      ${avatar(u)}
      <div style="flex:1"><b style="font-size:14px">${esc(u.name)}</b><div class="muted small">${esc(u.dept)} Department</div><div class="muted small">${esc(u.email || 'No email')} · ${esc(u.phone || 'No phone')}</div></div>
      <div style="text-align:center;min-width:70px"><b style="font-size:18px;color:${load>=4?'var(--red)':''}">${load}</b><div class="muted small">active</div></div>
      <div style="text-align:center;min-width:70px"><b style="font-size:18px">${done}</b><div class="muted small">done</div></div>
      <div style="text-align:center;min-width:70px"><b style="font-size:18px;color:${red?'var(--red)':''}">${red}</b><div class="muted small">stuck</div></div>
      <div class="member-actions"><button class="btn-ghost small" data-edit-member="${u.id}" title="Edit ${esc(u.name)}">✏️ Edit</button><button class="btn-ghost small" data-delete-member="${u.id}" title="Delete ${esc(u.name)}">🗑️</button></div>
    </div>`;
  }).join('');
  return `<div class="section-head"><p class="muted">Each person owns their queue — accountability is explicit. Load balancing sends new work to the least-loaded person in the right department.</p><button class="btn small" data-add-member>+ Add team member</button></div><div class="list">${rows || '<div class="empty"><div class="e-ic">👥</div>No team members yet</div>'}</div>`;
}

function openTeamMember(memberId=null){
  const member = memberId ? userById(memberId) : null;
  const modal=document.createElement('div'); modal.className='modal-scrim';
  modal.innerHTML=`<div class="modal"><div class="modal-head"><h2>${member?'Edit':'Add'} team member</h2><button class="btn-ghost" data-close>✕</button></div>
    <div class="modal-body">
      <div class="field"><label>Name <span class="req">*</span></label><input id="tm-name" value="${esc(member?.name || '')}" placeholder="Full name"></div>
      <div class="form-row">
        <div class="field"><label>Email <span class="req">*</span></label><input id="tm-email" type="email" value="${esc(member?.email || '')}" placeholder="name@company.com"></div>
        <div class="field"><label>Phone <span class="req">*</span></label><input id="tm-phone" type="tel" value="${esc(member?.phone || '')}" placeholder="+91 98765 43210"></div>
      </div>
      <div class="field"><label>Department <span class="req">*</span></label><select id="tm-dept">${DEPARTMENTS.map(d=>`<option ${member?.dept===d?'selected':''}>${d}</option>`).join('')}</select></div>
    </div><div class="modal-foot"><button class="btn-ghost" data-close>Cancel</button><button class="btn" id="tm-save">${member?'Save changes':'Add member'}</button></div></div>`;
  $('#modal-host').appendChild(modal);
  modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.remove());
  modal.onclick=e=>{if(e.target===modal)modal.remove();};
  $('#tm-save').onclick=async()=>{
    const name=$('#tm-name').value.trim(), email=$('#tm-email').value.trim(), phone=$('#tm-phone').value.trim(), dept=$('#tm-dept').value;
    if(!name || !email || !phone){ toast('Name, email and phone are required'); return; }
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ toast('Enter a valid email address'); return; }
    if(S().users.some(u=>u.id!==memberId && u.email && u.email.toLowerCase()===email.toLowerCase())){ toast('That email is already in use'); return; }
    const previous = member ? {...member} : null;
    const added = member ? null : {id:'u_'+Math.random().toString(36).slice(2,9),name,email,phone,dept,role:'team',color:DEPT_COLOR[dept]?.fg || '#7a5c3e'};
    if(member) Object.assign(member,{name,email,phone,dept});
    else S().users.push(added);
    const saveButton = $('#tm-save');
    saveButton.disabled = true; saveButton.textContent = 'Saving…';
    try {
      await Store.save(); modal.remove(); render(); toast(member?'✅ Team member updated':'✅ Team member added');
    } catch (error) {
      if(member) Object.assign(member, previous);
      else S().users = S().users.filter(u=>u.id!==added.id);
      saveButton.disabled = false; saveButton.textContent = member?'Save changes':'Add member';
      toast(`Could not save team member: ${error.message}`);
    }
  };
}

async function deleteTeamMember(memberId){
  const member=userById(memberId); if(!member) return;
  if(!confirm(`Delete ${member.name}? Their assigned tasks will become unassigned.`)) return;
  S().tasks.forEach(t=>{ if(t.ownerId===memberId) t.ownerId=null; });
  S().users=S().users.filter(u=>u.id!==memberId);
  await Store.save(); render(); toast('🗑️ Team member deleted');
}

/* ---------- SETTINGS ---------- */
function viewSettings(){
  const {amberMin,redMin}=S().settings;
  const rules = S().rules.map((r,i)=>`
    <div class="row-item">
      <span class="tag-dep" style="background:${DEPT_COLOR[r.dept].bg};color:${DEPT_COLOR[r.dept].fg}">${r.dept}</span>
      <input data-rule="${i}" value="${esc(r.kw)}" style="flex:1;padding:8px 11px;border:1px solid var(--line);border-radius:9px">
    </div>`).join('');
  return `
    <div class="grid cols-2">
      <div class="card">
        <h3>🚦 Andon thresholds</h3>
        <p class="muted small" style="margin-bottom:14px">How long a task can sit in a stage before it warns / alerts you.</p>
        <div class="field"><label>Turn 🟡 amber after (minutes)</label><input id="set-amber" type="number" value="${amberMin}"></div>
        <div class="field"><label>Turn 🔴 red after (minutes)</label><input id="set-red" type="number" value="${redMin}"></div>
        <button class="btn small" id="save-thresh">Save thresholds</button>
      </div>
      <div class="card">
        <h3>🎯 Auto-delegation rules</h3>
        <p class="muted small" style="margin-bottom:14px">When a brief contains these keywords, it routes to that department automatically.</p>
        <div class="list">${rules}</div>
        <button class="btn small" id="save-rules" style="margin-top:12px">Save rules</button>
      </div>
      <div class="card">
        <h3>🔌 Integrations (WhatsApp + Email)</h3>
        <p class="muted small" style="margin-bottom:14px">Currently <b>simulated</b> — every event logs the exact message that would send. Connect a provider to go live.</p>
        <div class="field"><label>WhatsApp provider</label><select><option>Meta WhatsApp Cloud API</option><option>Twilio</option><option>Gupshup</option></select></div>
        <div class="field"><label>Email provider</label><select><option>Resend</option><option>SendGrid</option><option>Amazon SES</option></select></div>
        <button class="btn-2 small" onclick="toast('Provider connection is wired in the full build ⚡')">Connect (next phase)</button>
      </div>
      <div class="card">
        <h3>🧹 Prototype data</h3>
        <p class="muted small" style="margin-bottom:14px">Reset all demo data (clients, tasks, messages) back to the starting sample.</p>
        <button class="btn-danger small" id="reset-data">Reset demo data</button>
      </div>
    </div>`;
}

/* ============================================================
   CLIENT VIEWS
============================================================ */
function viewMyRequests(){
  const ts = tasksOf(session.clientId).sort((a,b)=>b.createdAt-a.createdAt);
  const rows = ts.map(t=>{
    const owner=userById(t.ownerId);
    const statusLabel = {new:'Received',todo:'Queued',in_progress:'Being worked on',review:'In review',done:'Completed',blocked:'On hold'}[t.status];
    return `<div class="row-item" data-task="${t.id}" style="cursor:pointer">
      <div style="flex:1"><b style="font-size:14px">${esc(t.title)}</b><div class="muted small">${t.dept} team${owner?' · '+esc(owner.name):''} · ${timeAgo(t.createdAt)}</div></div>
      <span class="status-pill st-${t.status}">${statusLabel}</span>
    </div>`;
  }).join('');
  return `
    <div class="section-head"><h2>Your requests</h2><button class="btn small" data-go="new-request">+ New request</button></div>
    <p class="muted" style="margin-bottom:16px">Track everything you've asked us to do and its live status.</p>
    <div class="list">${rows||'<div class="empty"><div class="e-ic">📋</div>No requests yet — send us your first!</div>'}</div>`;
}

function viewNewRequest(){
  return `
    <div class="card" style="max-width:640px">
      <h2 style="margin-bottom:6px">Send us a new request</h2>
      <p class="muted small" style="margin-bottom:20px">Tell us what you need. We'll route it to the right team automatically and confirm on WhatsApp + email.</p>
      <div class="field" id="f-title"><label>What do you need? <span class="req">*</span></label>
        <input id="req-title" placeholder="e.g. Instagram post for our new product"></div>
      <div class="field" id="f-desc"><label>Details <span class="req">*</span></label>
        <textarea id="req-desc" placeholder="Describe it — sizes, deadline, references, tone…"></textarea>
        <div class="hint">The more detail, the faster we deliver (Poka-yoke: required so nothing is vague).</div></div>
      <div class="form-row">
        <div class="field"><label>Priority</label>
          <select id="req-prio"><option value="low">Low</option><option value="med" selected>Normal</option><option value="high">Urgent</option></select></div>
        <div class="field"><label>Needed by</label><input id="req-due" type="date"></div>
      </div>
      <div class="field"><label>Attach a voice note (optional)</label>
        <div id="req-voice-box"></div></div>
      <div class="field"><label>Attachment files (optional)</label>
        <label class="file-picker" for="req-files"><span>📎 Choose files</span><small>Images, PDFs, documents, or other reference files · 5 MB total</small></label>
        <input class="file-input" id="req-files" type="file" multiple>
        <div id="req-file-list" class="attachment-list"></div>
      </div>
      <button class="btn" id="submit-req">Send request →</button>
    </div>`;
}

function viewMessages(){
  const msgs = S().messages.filter(m=>m.clientId===session.clientId).sort((a,b)=>a.at-b.at);
  return `
    <div class="card" style="max-width:800px">
      <h3>Chat with your team</h3>
      <p class="muted small" style="margin-bottom:16px">Text or voice — no WhatsApp needed. We're notified instantly.</p>
      ${renderChat(msgs)}
      ${composerHTML(session.clientId, null)}
    </div>`;
}

/* ============================================================
   CHAT + COMPOSER (shared)
============================================================ */
function renderChat(msgs){
  if (!msgs.length) return '<div class="empty" style="padding:30px"><div class="e-ic">💬</div>No messages yet</div>';
  return `<div class="chat">${msgs.map(m=>{
    const out = session.role!=='client' ? m.fromRole!=='client' : m.fromRole==='client';
    const u = userById(m.fromId);
    const body = m.voice
      ? `<div class="voice-note">🎤 <audio controls src="${m.voice}"></audio></div>`
      : esc(m.text);
    return `<div class="msg ${out?'out':''}">
      ${avatar(u,'')}
      <div><div class="who">${esc(u?u.name:'?')}</div><div class="bubble">${body}</div><div class="time">${clockTime(m.at)}</div></div>
    </div>`;
  }).join('')}</div>`;
}
function composerHTML(clientId, taskId){
  return `<div class="composer" data-client="${clientId}" data-task="${taskId||''}">
    <button class="mic-btn" data-mic title="Record voice note">🎤</button>
    <textarea data-msg placeholder="Type a message…" rows="1"></textarea>
    <button class="btn small" data-send>Send</button>
  </div>`;
}

/* ============================================================
   VOICE RECORDING
============================================================ */
let mediaRec=null, chunks=[], recTarget=null;
async function toggleRecord(btn, onDone){
  if (mediaRec && mediaRec.state==='recording'){ mediaRec.stop(); return; }
  try{
    const stream = await navigator.mediaDevices.getUserMedia({audio:true});
    mediaRec = new MediaRecorder(stream); chunks=[];
    mediaRec.ondataavailable = e=>chunks.push(e.data);
    mediaRec.onstop = ()=>{
      stream.getTracks().forEach(t=>t.stop());
      const blob = new Blob(chunks, {type:'audio/webm'});
      const fr = new FileReader();
      fr.onload = ()=> onDone(fr.result);
      fr.readAsDataURL(blob);
      btn.classList.remove('rec'); btn.textContent='🎤';
    };
    mediaRec.start();
    btn.classList.add('rec'); btn.textContent='⏹';
    toast('🎙️ Recording… tap again to stop');
  }catch(e){ toast('🎤 Microphone blocked — allow mic access to record'); }
}

/* ============================================================
   SEND MESSAGE
============================================================ */
function sendMessage(clientId, taskId, text, voice){
  if (!text && !voice) return;
  const msg = { id:Math.random().toString(36).slice(2,9), clientId, taskId:taskId||null, fromId:session.id, fromRole:session.role==='client'?'client':'team', text:text||'', voice:voice||null, at:Date.now() };
  S().messages.push(msg); Store.save();
  const c = clientById(clientId);
  if (session.role==='client'){
    logActivity(`${c.name} (${c.company}) sent a ${voice?'voice note':'message'}`,'msg');
    Notify.both({ phone:'+91 agency-team', email:'team@antrajaal.com',
      waText:`💬 New message from ${c.name} (${c.company}): "${voice?'🎤 voice note':text}"`,
      emailText:`Subject: New message from ${c.company}` });
  } else {
    Notify.both({ phone:c.phone, email:c.email,
      waText:`💬 ${session.name} from Antrajaal replied: "${voice?'🎤 voice note':text}"`,
      emailText:`Subject: Reply from your Antrajaal team` });
  }
  render();
}

/* ============================================================
   SUBMIT NEW REQUEST (client)
============================================================ */
let pendingVoice=null, pendingAttachments=[];
function submitRequest(){
  const title=$('#req-title').value.trim();
  const desc=$('#req-desc').value.trim();
  let ok=true;
  $('#f-title').classList.toggle('err', !title); if(!title) ok=false;
  $('#f-desc').classList.toggle('err', !desc); if(!desc) ok=false;
  if (!ok){ toast('Please fill the required fields'); return; }
  const dept = delegate(title+' '+desc);
  const owner = pickOwner(dept);
  const due = $('#req-due').value ? new Date($('#req-due').value).getTime() : Date.now()+86400000;
  const task = { id:Math.random().toString(36).slice(2,9), clientId:session.clientId, title, desc, dept, ownerId:owner, status:'new', priority:$('#req-prio').value, createdAt:Date.now(), stageAt:Date.now(), dueDate:due, recurring:null, attachments:pendingAttachments };
  S().tasks.push(task);
  if (pendingVoice){ sendMessageRaw(session.clientId, task.id, '', pendingVoice); }
  Store.save();
  const c=clientById(session.clientId);
  const ownerU=userById(owner);
  logActivity(`New brief from ${c.company} → auto-delegated to ${dept}${ownerU?' ('+ownerU.name+')':''}`,'brief');
  // notify agency
  Notify.both({ phone:'+91 agency-team', email:'team@antrajaal.com',
    waText:`📥 New request from ${c.name} (${c.company}): "${title}" → assigned to ${dept}`,
    emailText:`Subject: New brief — ${title}` });
  // confirm to client
  Notify.both({ phone:c.phone, email:c.email,
    waText:`✅ Hi ${c.name}, we received "${title}" and assigned it to our ${dept} team. We'll keep you posted!`,
    emailText:`Subject: We got your request — ${title}` });
  pendingVoice=null;
  pendingAttachments=[];
  toast(`✅ Request sent — routed to ${dept} team`);
  go('my-requests');
}
function sendMessageRaw(clientId, taskId, text, voice){
  S().messages.push({ id:Math.random().toString(36).slice(2,9), clientId, taskId, fromId:session.id, fromRole:'client', text, voice, at:Date.now() });
}

/* ============================================================
   TASK MODAL
============================================================ */
function openTask(id){
  const t = S().tasks.find(x=>x.id===id); if(!t) return;
  const c=clientById(t.clientId);
  const isStaff = session.role!=='client';
  const msgs = S().messages.filter(m=>m.taskId===t.id).sort((a,b)=>a.at-b.at);
  const teamOpts = S().users.filter(u=>u.role==='team').map(u=>`<option value="${u.id}" ${u.id===t.ownerId?'selected':''}>${esc(u.name)} · ${u.dept}</option>`).join('');
  const lvl=andonLevel(t);
  const staffControls = isStaff ? `
    <div class="form-row" style="margin-top:8px">
      <div class="field"><label>Status / stage</label>
        <select id="m-status">${COLS.map(col=>`<option value="${col.id}" ${t.status===col.id?'selected':''}>${col.label}</option>`).concat(`<option value="blocked" ${t.status==='blocked'?'selected':''}>⛔ Blocked</option>`).join('')}</select></div>
      <div class="field"><label>Owner (accountable)</label><select id="m-owner">${teamOpts}</select></div>
    </div>
    <div class="form-row">
      <div class="field"><label>Priority</label><select id="m-prio">${['low','med','high'].map(p=>`<option ${t.priority===p?'selected':''}>${p}</option>`).join('')}</select></div>
      <div class="field"><label>Department</label><select id="m-dept">${DEPARTMENTS.map(d=>`<option ${t.dept===d?'selected':''}>${d}</option>`).join('')}</select></div>
    </div>` : '';
  const modal = document.createElement('div');
  modal.className='modal-scrim';
  modal.innerHTML = `<div class="modal">
    <div class="modal-head">
      <div style="display:flex;align-items:center;gap:10px">${ACTIVE.includes(t.status)?`<span class="light ${lvl}"></span>`:''}<h2>${esc(t.title)}</h2></div>
      <button class="btn-ghost" data-close>✕</button>
    </div>
    <div class="modal-body">
      <div class="chips" style="margin-bottom:14px">
        <span class="chip" style="cursor:default">📁 ${esc(c.company)}</span>
        <span class="chip" style="cursor:default">${t.dept}</span>
        <span class="chip" style="cursor:default">⏱ ${ACTIVE.includes(t.status)?fmtElapsed(t)+' in stage':'—'}</span>
        ${t.recurring?`<span class="chip" style="cursor:default">🔁 ${t.recurring}</span>`:''}
      </div>
      <p style="margin-bottom:6px">${esc(t.desc)||'<span class="muted">No description</span>'}</p>
      ${renderTaskAttachments(t.attachments)}
      ${staffControls}
      <div class="divider"></div>
      <h3 style="font-size:14px;margin-bottom:10px">💬 Conversation on this task</h3>
      ${renderChat(msgs)}
      ${composerHTML(t.clientId, t.id)}
    </div>
    ${isStaff?`<div class="modal-foot"><button class="btn-ghost" data-close>Close</button><button class="btn" data-save-task="${t.id}">Save changes</button></div>`:'<div class="modal-foot"><button class="btn" data-close>Close</button></div>'}
  </div>`;
  $('#modal-host').appendChild(modal);
  modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.remove());
  modal.onclick=e=>{ if(e.target===modal) modal.remove(); };
  bindComposer(modal);
  const saveBtn = modal.querySelector('[data-save-task]');
  if (saveBtn) saveBtn.onclick=()=>{
    const ns=$('#m-status').value;
    if (ns!==t.status){ t.stageAt=Date.now(); logActivity(`${session.name} moved "${t.title}" to ${ns.replace('_',' ')}`,'move');
      if (ns==='done'){ Notify.both({phone:c.phone,email:c.email,waText:`🎉 Good news ${c.name}! "${t.title}" is completed. Please review.`,emailText:`Subject: Completed — ${t.title}`}); }
    }
    t.status=ns; t.ownerId=$('#m-owner').value; t.priority=$('#m-prio').value; t.dept=$('#m-dept').value;
    Store.save(); modal.remove(); render(); buildNav(); toast('✅ Task updated');
  };
}

/* ============================================================
   NEW RECURRING MODAL
============================================================ */
function openNewRecurring(){
  const clientOpts=S().clients.map(c=>`<option value="${c.id}">${esc(c.company)}</option>`).join('');
  const modal=document.createElement('div'); modal.className='modal-scrim';
  modal.innerHTML=`<div class="modal"><div class="modal-head"><h2>New repeating task</h2><button class="btn-ghost" data-close>✕</button></div>
    <div class="modal-body">
      <div class="field"><label>Title</label><input id="rc-title" placeholder="e.g. Weekly Instagram carousel"></div>
      <div class="field"><label>Client</label><select id="rc-client">${clientOpts}</select></div>
      <div class="form-row">
        <div class="field"><label>Department</label><select id="rc-dept">${DEPARTMENTS.map(d=>`<option>${d}</option>`).join('')}</select></div>
        <div class="field"><label>Repeats</label><select id="rc-freq"><option>daily</option><option selected>weekly</option><option>monthly</option></select></div>
      </div>
    </div>
    <div class="modal-foot"><button class="btn-ghost" data-close>Cancel</button><button class="btn" id="rc-save">Create</button></div></div>`;
  $('#modal-host').appendChild(modal);
  modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.remove());
  modal.onclick=e=>{if(e.target===modal)modal.remove();};
  $('#rc-save').onclick=()=>{
    const title=$('#rc-title').value.trim(); if(!title){toast('Add a title');return;}
    const dept=$('#rc-dept').value;
    S().tasks.push({id:Math.random().toString(36).slice(2,9),clientId:$('#rc-client').value,title,desc:'Auto-generated repeating task',dept,ownerId:pickOwner(dept),status:'todo',priority:'med',createdAt:Date.now(),stageAt:Date.now(),dueDate:Date.now()+604800000,recurring:$('#rc-freq').value});
    Store.save(); modal.remove(); render(); toast('🔁 Repeating task created');
  };
}

/* ============================================================
   BIND VIEW EVENTS
============================================================ */
function bindView(){
  $$('[data-go]').forEach(e=>e.onclick=()=>go(e.dataset.go));
  $$('[data-client]').forEach(e=>e.onclick=()=>go('client-folder', e.dataset.client));
  $$('[data-task]').forEach(e=>e.onclick=(ev)=>{ if(ev.target.closest('.composer'))return; openTask(e.dataset.task); });
  const sr=$('#submit-req'); if(sr) sr.onclick=submitRequest;
  const nr=$('[data-new-recurring]'); if(nr) nr.onclick=openNewRecurring;
  const am=$('[data-add-member]'); if(am) am.onclick=()=>openTeamMember();
  $$('[data-edit-member]').forEach(b=>b.onclick=()=>openTeamMember(b.dataset.editMember));
  $$('[data-delete-member]').forEach(b=>b.onclick=()=>deleteTeamMember(b.dataset.deleteMember));
  // settings
  const st=$('#save-thresh'); if(st) st.onclick=()=>{ S().settings.amberMin=+$('#set-amber').value||15; S().settings.redMin=+$('#set-red').value||25; Store.save(); toast('✅ Thresholds saved'); buildNav(); };
  const sru=$('#save-rules'); if(sru) sru.onclick=()=>{ $$('[data-rule]').forEach(inp=>S().rules[+inp.dataset.rule].kw=inp.value); Store.save(); toast('✅ Delegation rules saved'); };
  const rd=$('#reset-data'); if(rd) rd.onclick=async()=>{ await Store.reset(); toast('🧹 Demo data reset'); render(); buildNav(); };
  // voice box on new request
  const vb=$('#req-voice-box');
  if (vb){ renderReqVoice(vb); }
  const fileInput=$('#req-files');
  if (fileInput) fileInput.onchange=()=>addRequestFiles(fileInput.files);
  // composers in view
  bindComposer($('#view'));
}
function addRequestFiles(files){
  let total=pendingAttachments.reduce((sum,a)=>sum+a.size,0);
  [...files].forEach(file=>{
    if (total+file.size > 5*1024*1024){ toast('Attachments can be up to 5 MB in total'); return; }
    total+=file.size;
    const reader=new FileReader();
    reader.onload=()=>{ pendingAttachments.push({id:Math.random().toString(36).slice(2,9),name:file.name,type:file.type||'application/octet-stream',size:file.size,data:reader.result}); renderRequestFiles(); };
    reader.readAsDataURL(file);
  });
  $('#req-files').value='';
}
function renderRequestFiles(){
  const list=$('#req-file-list'); if(!list) return;
  list.innerHTML=pendingAttachments.map(a=>`<div class="attachment-item"><span>📄</span><div><b>${esc(a.name)}</b><small>${formatFileSize(a.size)}</small></div><button class="btn-ghost small" type="button" data-remove-file="${a.id}">Remove</button></div>`).join('');
  $$('[data-remove-file]',list).forEach(btn=>btn.onclick=()=>{pendingAttachments=pendingAttachments.filter(a=>a.id!==btn.dataset.removeFile);renderRequestFiles();});
}
function formatFileSize(bytes){ return bytes<1024 ? `${bytes} B` : bytes<1048576 ? `${(bytes/1024).toFixed(1)} KB` : `${(bytes/1048576).toFixed(1)} MB`; }
function renderTaskAttachments(attachments){
  if (!attachments?.length) return '';
  return `<div class="task-attachments"><h3>📎 Attachments</h3>${attachments.map(a=>`<a class="attachment-item" href="${a.data}" download="${esc(a.name)}"><span>📄</span><div><b>${esc(a.name)}</b><small>${formatFileSize(a.size)}</small></div><span class="download-mark">Download</span></a>`).join('')}</div>`;
}
function renderReqVoice(box){
  box.innerHTML = pendingVoice
    ? `<div class="voice-note" style="padding:8px 0">🎤 <audio controls src="${pendingVoice}"></audio> <button class="btn-ghost small" id="clear-voice">Remove</button></div>`
    : `<button class="mic-btn" id="req-mic" style="width:auto;border-radius:10px;padding:10px 16px;font-size:14px;font-weight:600">🎤 Record voice note</button>`;
  const mic=$('#req-mic'); if(mic) mic.onclick=()=>toggleRecord(mic,(data)=>{pendingVoice=data;renderReqVoice(box);});
  const cv=$('#clear-voice'); if(cv) cv.onclick=()=>{pendingVoice=null;renderReqVoice(box);};
}
function bindComposer(root){
  $$('.composer', root).forEach(comp=>{
    const cid=comp.dataset.client, tid=comp.dataset.task||null;
    const ta=comp.querySelector('[data-msg]');
    const send=comp.querySelector('[data-send]');
    const mic=comp.querySelector('[data-mic]');
    const doSend=()=>{ const v=ta.value.trim(); if(!v)return; sendMessage(cid,tid,v,null); };
    if(send) send.onclick=doSend;
    if(ta) ta.onkeydown=e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); doSend(); } };
    if(mic) mic.onclick=()=>toggleRecord(mic,(data)=>sendMessage(cid,tid,'',data));
  });
}

/* ============================================================
   LIVE TIMER TICK (updates andon lights + timers every second)
============================================================ */
setInterval(()=>{
  if (!session) return;
  $$('[data-timer]').forEach(el=>{
    const t=S().tasks.find(x=>x.id===el.dataset.timer); if(!t)return;
    el.textContent=fmtElapsed(t);
    const lvl=andonLevel(t);
    el.className='timer '+(lvl==='green'?'':lvl);
    const row=el.closest('.andon-row'); if(row){ row.className='andon-row '+lvl; row.querySelector('.light').className='light '+lvl; }
  });
}, 1000);

// refresh whole board occasionally so cards re-sort / lights update
setInterval(()=>{ if(session && ['andon','dashboard','kanban'].includes(route)) render(); }, 30000);

/* ============================================================
   GLOBAL WIRING
============================================================ */
$$('.ltab').forEach(t=>t.onclick=()=>{ $$('.ltab').forEach(x=>x.classList.remove('active')); t.classList.add('active'); renderLogin(); });
$('#logout').onclick=signOut;
$('#bell').onclick=()=>toggleDrawer(true);
$('#notif-close').onclick=()=>toggleDrawer(false);
$('#scrim').onclick=()=>toggleDrawer(false);
function toggleDrawer(open){
  $('#notif-drawer').classList.toggle('hidden',!open);
  $('#scrim').classList.toggle('hidden',!open);
  if(open) renderNotifs();
}
function renderNotifs(){
  $('#notif-list').innerHTML = S().notifications.length ? S().notifications.map(n=>`
    <div class="notif">
      <div class="n-top"><span class="chan ${n.channel==='whatsapp'?'wa':'email'}">${n.channel==='whatsapp'?'WhatsApp':'Email'}</span><span class="muted small">${timeAgo(n.at)}</span></div>
      <div class="n-body">${esc(n.text)}</div>
      <div class="n-to">→ ${esc(n.to)}</div>
    </div>`).join('') : '<div class="empty"><div class="e-ic">🔕</div>No messages sent yet</div>';
}

/* ---------- boot ---------- */
(async function boot(){
  try {
    await Store.load();
  } catch (error) {
    document.body.innerHTML = `<div style="padding:40px;font-family:sans-serif"><h2>Nagare could not connect to the server</h2><p>${esc(error.message)}</p></div>`;
    return;
  }
  renderLogin();
  const saved=await Store.currentSession();
  if (saved && userById(saved)) signIn(saved);
})();
