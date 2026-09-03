/* ============ NAGARE · App ============ */
const S = () => Store.data;
const $ = (s, r=document) => r.querySelector(s);
const $$ = (s, r=document) => [...r.querySelectorAll(s)];

let session = null;   // current signed-in user
let route = 'dashboard';
let routeParam = null;
let tasksPage = 1;

/* ---------- utils ---------- */
const esc = (s='') => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const userById = id => S().users.find(u => u.id === id);
const assignableStaff = () => S().users.filter(u => u.role === 'team' || (u.role === 'admin' && u.email));
const assigneeLabel = u => `${esc(u.name)} · ${u.role === 'admin' ? 'Admin' : esc(u.dept || 'Team')}`;
const clientById = id => S().clients.find(c => c.id === id);
const projectById = id => (S().projects || []).find(p => p.id === id);
const departments = () => S().departments || [];
const departmentColor = name => {
  const color = departments().find(d=>d.name===name)?.color || DEPT_COLOR[name]?.fg || '#7a5c3e';
  return {fg:color,bg:`${color}20`};
};
const tasksOf = cid => S().tasks.filter(t => t.clientId === cid);
const initials = n => n.split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase();
const TASK_PROGRESS_OPTIONS = [['just_started','Just started'],['25','25% done'],['50','50% done'],['75','75% done'],['completed','Completed']];
const taskProgressOptions = value => TASK_PROGRESS_OPTIONS.map(([id,label])=>`<option value="${id}" ${(value||'just_started')===id?'selected':''}>${label}</option>`).join('');
const taskProgressLabel = value => TASK_PROGRESS_OPTIONS.find(([id])=>id===(value||'just_started'))?.[1] || 'Just started';

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
function dueCountdown(dueDate){
  const remaining=dueDate-Date.now(),totalMinutes=Math.floor(Math.abs(remaining)/60000);
  if(totalMinutes<1)return remaining>=0?'Less than 1 min remaining in submitting task':'Less than 1 min overdue for submitting task';
  const days=Math.floor(totalMinutes/1440),hours=Math.floor(totalMinutes%1440/60),minutes=totalMinutes%60;
  return `${days} day${days===1?'':'s'} ${hours} hrs ${minutes} mins ${remaining>=0?'remaining in submitting task':'overdue for submitting task'}`;
}

/* ---------- ANDON ---------- */
const ACTIVE = ['new','todo','in_progress','review'];
function elapsedMs(t){ return Date.now() - t.stageAt; }
function andonLevel(t){
  if (t.progress==='completed' || !ACTIVE.includes(t.status)) return 'done';
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
  return departments()[0]?.name || 'General';
}
function activeLoad(uid){ return S().tasks.filter(t=>t.ownerId===uid && ACTIVE.includes(t.status) && t.progress!=='completed').length; }

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
    {id:'projects', ic:'📌', label:'Projects'},
    {id:'andon', ic:'🚦', label:'Andon Board'},
    {id:'kanban', ic:'🗂️', label:'Kanban'},
    {id:'clients', ic:'📁', label:'Client Folders'},
    {id:'inbox', ic:'💬', label:'Inbox'},
    {id:'recurring', ic:'🔁', label:'Recurring Tasks'},
    {id:'tasks', ic:'✅', label:'Tasks'},
    {sep:'Manage'},
    {id:'departments', ic:'🏢', label:'Departments'},
    {id:'team', ic:'👥', label:'Team & Load'},
    {id:'settings', ic:'⚙️', label:'Settings'},
  ],
  team: [
    {id:'dashboard', ic:'📊', label:'My Dashboard'},
    {id:'tasks', ic:'✅', label:'Tasks'},
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
const TITLES = {dashboard:'Dashboard', projects:'Projects', andon:'Andon Board', kanban:'Kanban Flow', clients:'Client Folders', inbox:'Inbox', recurring:'Recurring Tasks', tasks:'Tasks', departments:'Departments', team:'Team & Workload', settings:'Settings', 'my-requests':'My Requests', 'new-request':'New Request', messages:'Messages', 'client-folder':'Client Folder'};
function render(){
  const pageTitle = $('#page-title');
  const v = $('#view');
  if (!pageTitle || !v || !session || !S()) return;
  pageTitle.textContent = TITLES[route] || '';
  const R = {
    dashboard: viewDashboard, projects: viewProjects, andon: viewAndon, kanban: viewKanban,
    clients: viewClients, 'client-folder': viewClientFolder, inbox: viewInbox,
    recurring: viewRecurring, tasks: viewTasks, departments: viewDepartments, team: viewTeam, settings: viewSettings,
    'my-requests': viewMyRequests, 'new-request': viewNewRequest, messages: viewMessages,
  };
  v.innerHTML = (R[route] || viewDashboard)();
  bindView();
}

/* ---------- DASHBOARD ---------- */
function viewDashboard(){
  const scope = session.role==='team' ? S().tasks.filter(t=>t.ownerId===session.id) : S().tasks;
  const active = scope.filter(t=>ACTIVE.includes(t.status) && t.progress!=='completed');
  const red = scope.filter(t=>andonLevel(t)==='red');
  const amber = scope.filter(t=>andonLevel(t)==='amber');
  const doneToday = scope.filter(t=>t.status==='done').length;
  const overdue = scope.filter(t=>t.dueDate < Date.now() && t.status!=='done').length;

  const kpi = (val,lab,sub='',subcolor='') => `<div class="kpi"><div class="k-val">${val}</div><div class="k-lab">${lab}</div>${sub?`<div class="k-sub" style="color:${subcolor}">${sub}</div>`:''}</div>`;

  const andonProjectCard=(project,tasks)=>{const projectTasks=project?S().tasks.filter(t=>t.projectId===project.id):tasks,complete=projectTasks.filter(t=>t.status==='done'||t.progress==='completed').length,progress=projectTasks.length?Math.round(complete/projectTasks.length*100):0,client=project?clientById(project.clientId):null;return `<div class="project-card andon-project-card">
    <div class="project-card-head"><div class="project-icon">🚦</div><span class="andon-count">${tasks.length} red</span></div>
    <div class="project-client">${project?esc(client?.company||'No client'):'Unassigned project'}</div>
    <h3>${project?esc(project.name):'Standalone tasks'}</h3>
    <p>${project?esc(project.desc||'Tasks in this project need immediate attention.'):'Tasks requiring attention that are not linked to a project.'}</p>
    <div class="andon-card-tasks">${tasks.map(t=>{const owner=userById(t.ownerId);return `<button class="andon-task-link" data-task="${t.id}"><span class="light red"></span><span><b>${esc(t.title)}</b><small>${owner?esc(owner.name):'Unassigned'} · ${fmtElapsed(t)}</small></span><span aria-hidden="true">→</span></button>`;}).join('')}</div>
    <div class="project-progress"><div><span>Project progress</span><b>${complete}/${projectTasks.length} tasks</b></div><div class="bar"><i style="width:${progress}%"></i></div></div>
    <div class="project-card-foot"><span class="status-pill andon-alert">Needs attention</span></div>
  </div>`;};
  const projectIds=[...new Set(red.map(t=>t.projectId).filter(id=>projectById(id)))];
  const groupedRed=projectIds.map(projectId=>andonProjectCard(projectById(projectId),red.filter(t=>t.projectId===projectId))).join('');
  const standaloneRed=red.filter(t=>!projectById(t.projectId));
  const redRows = red.length ? `<div class="folder-grid andon-project-grid">${groupedRed}${standaloneRed.length?andonProjectCard(null,standaloneRed):''}</div>` :
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
  const progressRows = team.map(u=>{
    const memberTasks=S().tasks.filter(t=>t.ownerId===u.id);
    return `<div class="row-item"><div style="flex:1;min-width:150px">${avatar(u)} <b>${esc(u.name)}</b><div class="muted small">${memberTasks.length} task${memberTasks.length===1?'':'s'}</div></div><div class="chips" style="justify-content:flex-end">${TASK_PROGRESS_OPTIONS.map(([id,label])=>`<span class="chip" style="cursor:default">${label}: <b>${memberTasks.filter(t=>(t.progress||'just_started')===id).length}</b></span>`).join('')}</div></div>`;
  }).join('');
  const ownProgressSummary=TASK_PROGRESS_OPTIONS.map(([id,label])=>`<span class="chip" style="cursor:default">${label}: <b>${scope.filter(t=>(t.progress||'just_started')===id).length}</b></span>`).join('');
  const ownProgressRows=scope.map(t=>`<div class="row-item" data-task="${t.id}" style="cursor:pointer"><div style="flex:1"><b>${esc(t.title)}</b><div class="muted small">${esc(projectById(t.projectId)?.name||'Standalone task')}</div></div><span class="status-pill">${taskProgressLabel(t.progress)}</span></div>`).join('');
  const adminTasks=session.role==='admin'?S().tasks.filter(t=>t.ownerId===session.id):[];
  const adminTaskRows=adminTasks.map(t=>{const project=projectById(t.projectId),client=clientById(t.clientId),completed=t.status==='done'||t.progress==='completed';return `<div class="row-item ${completed?'task-row-completed':''}" data-task="${t.id}" style="cursor:pointer"><span class="light ${completed?'green':andonLevel(t)}"></span><div style="flex:1;min-width:0"><b style="font-size:14px">${esc(t.title)}</b><div class="muted small">${esc(project?.name||'Standalone task')} · ${esc(client?.company||'No client')}</div></div><span class="status-pill">${taskProgressLabel(t.progress)}</span></div>`;}).join('');

  const feed = S().activity.slice(0,8).map(a=>`
    <div class="row-item" style="padding:10px 12px">
      <span style="font-size:16px">${a.type==='brief'?'📥':a.type==='msg'?'💬':a.type==='move'?'➡️':'•'}</span>
      <div style="flex:1"><div style="font-size:13px">${esc(a.text)}</div><div class="muted small">${timeAgo(a.at)}</div></div>
    </div>`).join('');

  return `
    ${session.role==='admin'?'<div class="section-head"><div></div><button class="btn" data-add-project>+ Add project</button></div>':session.role==='team'?'<div class="section-head"><div><h2>My work</h2><p class="muted small">Create and manage tasks assigned to you.</p></div><button class="btn" data-add-team-task>+ Add task</button></div>':''}
    <div class="grid cols-4" style="margin-bottom:22px">
      ${kpi(active.length, 'Active tasks')}
      ${kpi(red.length, 'Stuck (red) 🔴', red.length?'Needs attention':'All clear', red.length?'var(--red)':'var(--green)')}
      ${kpi(amber.length, 'Slowing (amber) 🟡')}
      ${kpi(overdue, 'Overdue', overdue?'Past due date':'On schedule', overdue?'var(--red)':'var(--green)')}
    </div>
    ${session.role==='admin'?`
      <div class="card">
        <div class="section-head"><h2>🚦 Andon — needs attention now</h2><button class="btn-ghost small" data-go="andon">View all →</button></div>
        ${redRows}
      </div>
      <div class="grid" style="grid-template-columns:1.4fr 1fr;margin-top:16px">
        <div class="card"><div class="section-head"><div><h3>My Tasks</h3><p class="muted small">Tasks assigned directly to ${esc(session.name)}.</p></div><span class="chip" style="cursor:default">${adminTasks.length} task${adminTasks.length===1?'':'s'}</span></div><div class="list">${adminTaskRows||'<div class="empty"><div class="e-ic">✅</div>No tasks are assigned to you</div>'}</div></div>
        <div class="card"><h3>Team workload (Heijunka)</h3>${workload || '<span class="muted small">No team members</span>'}</div>
      </div>`:`
      <div class="card"><div class="section-head"><h2>🚦 Andon — needs attention now</h2><button class="btn-ghost small" data-go="andon">View all →</button></div>${redRows}</div>
      <div class="card" style="margin-top:16px"><h3>Team workload (Heijunka)</h3>${workload || '<span class="muted small">No team members</span>'}</div>`}
    ${session.role==='admin'?`<div class="card" style="margin-top:16px"><div class="section-head"><div><h3>Task progress by team member</h3><p class="muted small">Includes project tasks and standalone tasks.</p></div></div><div class="list">${progressRows||'<span class="muted">No team members</span>'}</div></div>`:''}
    ${session.role==='team'?`<div class="card" style="margin-top:16px"><div class="section-head"><div><h3>My task progress</h3><p class="muted small">Your project and standalone tasks.</p></div><div class="chips">${ownProgressSummary}</div></div><div class="list">${ownProgressRows||'<div class="empty">No tasks assigned to you</div>'}</div></div>`:''}
    <div class="card" style="margin-top:16px"><h3>Live activity (Gemba feed)</h3><div class="list">${feed||'<span class="muted">No activity yet</span>'}</div></div>
  `;
}

/* ---------- PROJECTS ---------- */
function viewProjects(){
  const projects = S().projects || [];
  const cards = projects.map(p=>{
    const c=clientById(p.clientId); const tasks=S().tasks.filter(t=>t.projectId===p.id);
    const complete=tasks.filter(t=>t.status==='done').length;
    const progress=tasks.length?Math.round(complete/tasks.length*100):0;
    return `<div class="project-card">
      <div class="project-card-head">
        <div class="project-icon">📌</div>
        <div class="project-actions">
          <button class="project-menu-btn" data-project-menu="${p.id}" aria-label="Project actions" aria-expanded="false">⋮</button>
          <div class="project-menu hidden" data-project-menu-popup="${p.id}">
            <button data-edit-project="${p.id}"><span>✏️</span>Edit project</button>
            <button class="danger" data-delete-project="${p.id}"><span>🗑️</span>Delete project</button>
          </div>
        </div>
      </div>
      <div class="project-client">${esc(c?.company||'No client')}</div>
      <h3>${esc(p.name)}</h3>
      ${p.desc?`<p>${esc(p.desc)}</p>`:'<p class="muted">No project description</p>'}
      <div class="project-progress"><div><span>Progress</span><b>${complete}/${tasks.length} tasks</b></div><div class="bar"><i style="width:${progress}%"></i></div></div>
      <div class="project-card-foot"><span class="status-pill st-${p.status||'new'}">${esc(p.status||'active')}</span></div>
    </div>`;
  }).join('');
  return `<div class="section-head"><p class="muted">Create a project and assign every task to a team member or admin.</p><button class="btn" data-add-project>+ Add project</button></div>
    <div class="folder-grid">${cards||'<div class="empty"><div class="e-ic">📌</div>No projects yet</div>'}</div>`;
}

function projectTaskRow(index, task=null){
  const members=assignableStaff();
  const departmentOptions=departments().map(d=>`<option value="${esc(d.name)}" ${task?.dept===d.name?'selected':''}>${esc(d.name)}</option>`).join('');
  return `<div class="project-task-row" data-project-task="${index}" ${task?`data-task-id="${task.id}"`:''}>
    <div class="project-task-head"><div class="project-task-number">${index+1}</div><div><b>${task?'Existing task':'New task'}</b><span>${task?'Update its details or assignment':'Define and assign this task'}</span></div><button class="btn-ghost small" type="button" data-remove-project-task>Remove</button></div>
    <div class="field"><label>Task title <span class="req">*</span></label><input data-pt-title value="${esc(task?.title||'')}" placeholder="e.g. Design homepage mockup"></div>
    <div class="form-row">
      <div class="field"><label>Assign to <span class="req">*</span></label><select data-pt-owner><option value="">Select team member or admin</option>${members.map(u=>`<option value="${u.id}" ${u.id===task?.ownerId?'selected':''}>${assigneeLabel(u)}</option>`).join('')}</select></div>
      <div class="field"><label>Priority</label><select data-pt-priority>${['low','med','high'].map(p=>`<option value="${p}" ${(task?.priority||'med')===p?'selected':''}>${p==='med'?'Medium':p[0].toUpperCase()+p.slice(1)}</option>`).join('')}</select></div>
    </div>
    <div class="form-row">
      <div class="field"><label>Progress</label><select data-pt-progress>${taskProgressOptions(task?.progress)}</select></div>
      <div class="field"><label>Due date</label><input data-pt-due type="date" value="${task?.dueDate?new Date(task.dueDate).toISOString().slice(0,10):''}"></div>
    </div>
    <div class="field"><label>Department <span class="req">*</span></label><select data-pt-dept><option value="">Select department</option>${departmentOptions}</select></div>
    <div class="field"><label>Description</label><textarea data-pt-desc rows="2" placeholder="What needs to be done?">${esc(task?.desc||'')}</textarea></div>
    <div class="field project-task-attachments" style="margin-bottom:0"><label>Attachments</label><label class="attachment-upload"><input type="file" data-pt-files multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"><span>📎 Choose images, PDFs or documents</span></label><div class="hint">Up to 5 MB total per task.</div><div data-pt-file-list class="attachment-list"></div></div>
  </div>`;
}

function renderProjectTaskFiles(row){
  const list=$('[data-pt-file-list]',row),attachments=row._attachments||[];
  list.innerHTML=attachments.map(a=>`<div class="attachment-item"><span>${String(a.type||'').startsWith('image/')?'🖼️':'📄'}</span><div><b>${esc(a.name)}</b><small>${formatFileSize(a.size||0)}</small></div><button class="btn-ghost small" type="button" data-remove-pt-file="${a.id}">Remove</button></div>`).join('');
  $$('[data-remove-pt-file]',list).forEach(btn=>btn.onclick=()=>{row._attachments=attachments.filter(a=>a.id!==btn.dataset.removePtFile);renderProjectTaskFiles(row);});
}

function bindProjectTaskFiles(list){
  $$('[data-project-task]',list).forEach(row=>{
    if(!row._attachments){const existing=row.dataset.taskId?S().tasks.find(t=>t.id===row.dataset.taskId):null;row._attachments=[...(existing?.attachments||[])];row._reading=0;}
    renderProjectTaskFiles(row);
    const input=$('[data-pt-files]',row);input.onchange=()=>{
      let total=row._attachments.reduce((sum,a)=>sum+(a.size||0),0);
      [...input.files].forEach(file=>{
        if(total+file.size>5*1024*1024){toast('Attachments can be up to 5 MB per task');return;}
        total+=file.size;row._reading++;const reader=new FileReader();
        reader.onload=()=>{row._attachments.push({id:'a_'+Math.random().toString(36).slice(2,9),name:file.name,type:file.type||'application/octet-stream',size:file.size,data:reader.result});renderProjectTaskFiles(row);};
        reader.onloadend=()=>{row._reading--;};reader.readAsDataURL(file);
      });
      input.value='';
    };
  });
}

function openProjectForm(projectId=null){
  const project=projectById(projectId); const editing=!!project;
  const projectTasks=editing?S().tasks.filter(t=>t.projectId===project.id):[];
  const clientOpts=`<option value="" ${project?.clientId?'':'selected'}>No client</option>`+S().clients.map(c=>`<option value="${c.id}" ${c.id===project?.clientId?'selected':''}>${esc(c.company)}</option>`).join('');
  const modal=document.createElement('div'); modal.className='modal-scrim';
  modal.innerHTML=`<div class="modal project-modal"><div class="modal-head"><div><h2>${editing?'Edit project':'Create a new project'}</h2><p class="muted small">${editing?'Update project details, assignments, or add more tasks.':'Plan the work and assign ownership from the start.'}</p></div><button class="btn-ghost" data-close>✕</button></div>
    <div class="modal-body">
      <div class="project-form-section"><h3>Project details</h3><div class="form-row"><div class="field"><label>Project name <span class="req">*</span></label><input id="project-name" value="${esc(project?.name||'')}" placeholder="e.g. Website redesign"></div><div class="field"><label>Client</label><select id="project-client">${clientOpts}</select></div></div>
      <div class="field"><label>Description</label><textarea id="project-desc" placeholder="Project scope and goals">${esc(project?.desc||'')}</textarea></div></div>
      <div class="project-form-section"><div class="section-head"><div><h3>Task list</h3><p class="muted small">Every task must have one accountable team member or admin.</p></div><button class="btn-2 small" type="button" id="add-project-task">+ Add task</button></div>
      <div id="project-task-list">${projectTasks.length?projectTasks.map((t,i)=>projectTaskRow(i,t)).join(''):projectTaskRow(0)}</div></div>
    </div><div class="modal-foot"><button class="btn-ghost" data-close>Cancel</button><button class="btn" id="save-project">${editing?'Save changes':'Create project'}</button></div></div>`;
  $('#modal-host').appendChild(modal);
  const close=()=>modal.remove(); modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=close); modal.onclick=e=>{if(e.target===modal)close();};
  const list=$('#project-task-list',modal);
  const renumber=()=>$$('[data-project-task]',list).forEach((row,i)=>{$('.project-task-number',row).textContent=i+1;});
  const bindRemove=()=>$$('[data-remove-project-task]',list).forEach(b=>b.onclick=()=>{b.closest('[data-project-task]').remove();renumber();});
  bindRemove();bindProjectTaskFiles(list);
  $('#add-project-task',modal).onclick=()=>{list.insertAdjacentHTML('beforeend',projectTaskRow($$('[data-project-task]',list).length));bindRemove();bindProjectTaskFiles(list);};
  $('#save-project',modal).onclick=async()=>{
    const name=$('#project-name',modal).value.trim(); const rows=$$('[data-project-task]',list);
    if(!name){toast('Enter a project name');return;}
    if(!rows.length){toast('Add at least one task');return;}
    if(rows.some(row=>row._reading>0)){toast('Please wait for attachments to finish loading');return;}
    const invalid=rows.some(row=>!$('[data-pt-title]',row).value.trim()||!$('[data-pt-owner]',row).value||!$('[data-pt-dept]',row).value);
    if(invalid){toast('Every task needs a title, assignee and department');return;}
    const id=project?.id||'p_'+Math.random().toString(36).slice(2,9), clientId=$('#project-client',modal).value||null, now=Date.now();
    if(project) Object.assign(project,{clientId,name,desc:$('#project-desc',modal).value.trim(),dueDate:null});
    else S().projects.push({id,clientId,name,desc:$('#project-desc',modal).value.trim(),status:'active'});
    const retainedIds=rows.map(row=>row.dataset.taskId).filter(Boolean);
    const removedIds=projectTasks.filter(t=>!retainedIds.includes(t.id)).map(t=>t.id);
    S().tasks=S().tasks.filter(t=>!removedIds.includes(t.id));
    S().messages.forEach(m=>{if(removedIds.includes(m.taskId))m.taskId=null;});
    rows.forEach(row=>{const owner=userById($('[data-pt-owner]',row).value);const existing=row.dataset.taskId?S().tasks.find(t=>t.id===row.dataset.taskId):null;const dueInput=$('[data-pt-due]',row).value;const values={projectId:id,clientId,title:$('[data-pt-title]',row).value.trim(),desc:$('[data-pt-desc]',row).value.trim(),dept:$('[data-pt-dept]',row).value,ownerId:owner.id,priority:$('[data-pt-priority]',row).value,progress:$('[data-pt-progress]',row).value,dueDate:dueInput?new Date(dueInput).getTime():null,attachments:row._attachments||[]};if(existing)Object.assign(existing,values);else S().tasks.push({id:'t_'+Math.random().toString(36).slice(2,9),...values,status:'todo',createdAt:now,stageAt:now,recurring:null});});
    try{await Store.save();logActivity(`Project "${name}" ${editing?'updated':'created'} with ${rows.length} assigned task${rows.length===1?'':'s'}`,'brief');close();go('projects');toast(editing?'✅ Project updated':'✅ Project and tasks created');}catch(error){await Store.load();render();toast(error.message);}
  };
}

async function deleteProject(projectId){
  const project=projectById(projectId); if(!project)return;
  const taskIds=S().tasks.filter(t=>t.projectId===projectId).map(t=>t.id);
  if(!confirm(`Delete "${project.name}" and its ${taskIds.length} task${taskIds.length===1?'':'s'}? This cannot be undone.`))return;
  S().projects=S().projects.filter(p=>p.id!==projectId); S().tasks=S().tasks.filter(t=>t.projectId!==projectId);
  S().messages.forEach(m=>{if(taskIds.includes(m.taskId))m.taskId=null;});
  try{await Store.save();render();buildNav();toast('🗑️ Project deleted');}catch(error){await Store.load();render();toast(error.message);}
}

/* ---------- ANDON BOARD ---------- */
function andonRow(t){
  const lvl = andonLevel(t);
  const owner = userById(t.ownerId);
  const c = clientById(t.clientId);
  return `<div class="andon-row ${lvl}" data-task="${t.id}">
    <span class="light ${lvl}"></span>
    <div class="a-main"><b>${esc(t.title)}</b><span>${esc(c?.company||'No client')} · ${t.dept} · ${owner?esc(owner.name):'Unassigned'}</span></div>
    <span class="status-pill st-${t.status}">${t.status.replace('_',' ')}</span>
    <span class="timer ${lvl==='green'?'':lvl}" data-timer="${t.id}">${fmtElapsed(t)}</span>
  </div>`;
}
function viewAndon(){
  const scope = session.role==='team' ? S().tasks.filter(t=>t.ownerId===session.id) : S().tasks;
  const active = scope.filter(t=>ACTIVE.includes(t.status) && t.progress!=='completed')
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
  const dc = departmentColor(t.dept);
  const lvl = andonLevel(t);
  return `<div class="kcard" data-task="${t.id}">
    <div class="kc-top">
      <span class="dep" style="background:${dc.bg};color:${dc.fg}">${esc(t.dept || 'General')}</span>
      ${ACTIVE.includes(t.status)?`<span class="light ${lvl}" title="${fmtElapsed(t)}"></span>`:''}
      ${t.recurring?`<span title="repeats ${t.recurring.replaceAll('_',' ')}">🔁</span>`:''}
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
    return `<div class="folder client-folder-card" data-client="${c.id}">
      <div class="client-card-head"><div class="f-ic">📁</div>${session.role==='admin'?`<div class="project-actions"><button class="project-menu-btn" data-client-menu="${c.id}" aria-label="Client actions" aria-expanded="false">⋮</button><div class="project-menu hidden" data-client-menu-popup="${c.id}"><button data-edit-client="${c.id}"><span>✏️</span>Edit client</button><button class="danger" data-delete-client="${c.id}"><span>🗑️</span>Delete client</button></div></div>`:''}</div>
      <b>${esc(c.company)}</b>
      <div class="f-meta">${esc(c.name)} · ${esc(c.email||'No email')}</div>
      <div class="f-stats">
        <div><b>${ts.length}</b><span class="muted">total</span></div>
        <div><b>${active}</b><span class="muted">active</span></div>
        <div><b style="color:${red?'var(--red)':''}">${red}</b><span class="muted">stuck</span></div>
      </div>
    </div>`;
  }).join('');
  return `<div class="section-head"><p class="muted">Every client has a dedicated folder — all their briefs, files, voice notes and the full conversation in one place.</p>${session.role==='admin'?'<button class="btn" data-add-client>+ Add client</button>':''}</div><div class="folder-grid">${folders||'<div class="empty"><div class="e-ic">📁</div>No clients yet</div>'}</div>`;
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
      <div style="flex:1"><b style="font-size:14px">${esc(t.title)}</b><div class="muted small">${t.dept} · ${owner?esc(owner.name):'—'}${t.recurring?' · 🔁 '+t.recurring.replaceAll('_',' '):''}</div></div>
      <span class="status-pill st-${t.status}">${t.status.replace('_',' ')}</span>
    </div>`;
  }).join('');
  return `
    <div class="back-link" data-go="clients">← All clients</div>
    <div class="section-head">
      <div style="display:flex;align-items:center;gap:14px">${avatar(c)}<div><h2>${esc(c.company)}</h2><span class="muted small">${esc(c.name)} · ${esc(c.email)} · ${esc(c.phone)}</span></div></div>
      ${session.role==='admin'?`<div><button class="btn-ghost" data-edit-client="${c.id}">Edit client</button><button class="btn-ghost btn-delete-client" data-delete-client="${c.id}">Delete client</button></div>`:''}
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

function openClientForm(clientId=null){
  if(session.role!=='admin')return;
  const client=clientById(clientId),editing=!!client;
  const assignedProject=(S().projects||[]).find(p=>p.clientId===clientId);
  const projectOpts=`<option value="">No project</option>`+(S().projects||[]).map(p=>`<option value="${p.id}" ${p.id===assignedProject?.id?'selected':''}>${esc(p.name)}${p.clientId&&p.clientId!==clientId?` · ${esc(clientById(p.clientId)?.company||'Another client')}`:''}</option>`).join('');
  const modal=document.createElement('div');modal.className='modal-scrim';
  modal.innerHTML=`<div class="modal"><div class="modal-head"><div><h2>${editing?'Edit client':'Add new client'}</h2><p class="muted small">${editing?'Keep the client profile and login details up to date.':'A client folder and client login will be created together.'}</p></div><button class="btn-ghost" data-close>✕</button></div>
    <div class="modal-body">
      <div class="form-row"><div class="field"><label>Contact name <span class="req">*</span></label><input id="client-name" value="${esc(client?.name||'')}" placeholder="e.g. Priya Nair"></div><div class="field"><label>Company <span class="req">*</span></label><input id="client-company" value="${esc(client?.company||'')}" placeholder="e.g. Lumen Cafe"></div></div>
      <div class="form-row"><div class="field"><label>Email <span class="req">*</span></label><input id="client-email" type="email" value="${esc(client?.email||'')}" placeholder="client@company.com"></div><div class="field"><label>Phone <span class="req">*</span></label><input id="client-phone" type="tel" value="${esc(client?.phone||'')}" placeholder="+91 98765 43210"></div></div>
      <div class="field"><label>Project</label><select id="client-project">${projectOpts}</select><div class="hint">Choose a project to link to this client, or leave it as No project.</div></div>
      <div class="field"><label>Folder color</label><div class="color-field"><input id="client-color" type="color" value="${esc(client?.color||'#7a5c3e')}"><span class="muted small">Used for the client avatar and visual identity.</span></div></div>
    </div><div class="modal-foot"><button class="btn-ghost" data-close>Cancel</button><button class="btn" id="save-client">${editing?'Save changes':'Create client'}</button></div></div>`;
  $('#modal-host').appendChild(modal);const close=()=>modal.remove();modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=close);modal.onclick=e=>{if(e.target===modal)close();};
  $('#save-client',modal).onclick=async()=>{
    const name=$('#client-name',modal).value.trim(),company=$('#client-company',modal).value.trim(),email=$('#client-email',modal).value.trim(),phone=$('#client-phone',modal).value.trim(),color=$('#client-color',modal).value,projectId=$('#client-project',modal).value||null;
    if(!name||!company||!email||!phone){toast('Complete all required client fields');return;}
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){toast('Enter a valid email address');return;}
    const duplicate=S().clients.some(c=>c.id!==clientId&&String(c.email||'').toLowerCase()===email.toLowerCase());if(duplicate){toast('A client with this email already exists');return;}
    let savedClientId=client?.id;
    if(client){Object.assign(client,{name,company,email,phone,color});const login=S().users.find(u=>u.role==='client'&&(u.clientId===client.id||u.id===client.id));if(login)Object.assign(login,{name,company,email,phone,color,clientId:client.id});}
    else{savedClientId='c_'+Math.random().toString(36).slice(2,9);S().clients.push({id:savedClientId,name,company,email,phone,color});S().users.push({id:savedClientId,name,role:'client',dept:null,clientId:savedClientId,company,email,phone,color});}
    if(assignedProject&&assignedProject.id!==projectId){assignedProject.clientId=null;S().tasks.filter(t=>t.projectId===assignedProject.id).forEach(t=>t.clientId=null);}
    const selectedProject=projectById(projectId);if(selectedProject){selectedProject.clientId=savedClientId;S().tasks.filter(t=>t.projectId===selectedProject.id).forEach(t=>t.clientId=savedClientId);}
    try{await Store.save();logActivity(`Client ${company} ${editing?'updated':'added'}`,'brief');close();render();toast(editing?'✅ Client updated':'✅ Client folder created');}catch(error){await Store.load();render();toast(error.message);}
  };
}

async function deleteClient(clientId){
  if(session.role!=='admin')return;const client=clientById(clientId);if(!client)return;
  const taskIds=S().tasks.filter(t=>t.clientId===clientId).map(t=>t.id),projectCount=(S().projects||[]).filter(p=>p.clientId===clientId).length;
  if(!confirm(`Delete ${client.company}? This will also delete ${projectCount} project${projectCount===1?'':'s'}, ${taskIds.length} task${taskIds.length===1?'':'s'}, and all client conversations.`))return;
  S().messages=S().messages.filter(m=>m.clientId!==clientId);S().tasks=S().tasks.filter(t=>t.clientId!==clientId);S().projects=(S().projects||[]).filter(p=>p.clientId!==clientId);S().users=S().users.filter(u=>!(u.role==='client'&&(u.clientId===clientId||u.id===clientId)));S().clients=S().clients.filter(c=>c.id!==clientId);
  try{await Store.save();go('clients');toast('🗑️ Client and folder deleted');}catch(error){await Store.load();go('clients');toast(error.message);}
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
      <div style="flex:1"><b style="font-size:14px">${esc(t.title)}</b><div class="muted small">${esc(c?.company||'No client')} · ${t.dept} · ${owner?esc(owner.name):'—'} · Due: ${t.dueDate?new Date(t.dueDate).toLocaleDateString():'No due date'}</div></div>
      <span class="pill">${t.recurring.replaceAll('_',' ')}</span>
      <span class="status-pill">${taskProgressLabel(t.progress)}</span>
      <span class="status-pill st-${t.status}">${t.status.replace('_',' ')}</span>
      ${session.role==='admin'?`<div class="member-actions"><button class="btn-ghost small" data-edit-recurring="${t.id}">✏️ Edit</button><button class="btn-ghost small btn-delete-client" data-delete-recurring="${t.id}">🗑️ Delete</button></div>`:''}
    </div>`;
  }).join('');
  return `
    <div class="section-head"><h2>Repeating tasks</h2>${session.role==='admin'?'<button class="btn small" data-new-recurring>+ New repeating task</button>':''}</div>
    <p class="muted" style="margin-bottom:16px">These auto-create on schedule (daily / alternate days / weekly / monthly) — e.g. weekly social posts, monthly reports.</p>
    <div class="list">${rows||'<div class="empty"><div class="e-ic">🔁</div>No repeating tasks yet</div>'}</div>`;
}

/* ---------- TEAM ---------- */
function viewDepartments(){
  const rows=departments().map(dept=>{
    const people=S().users.filter(u=>u.role==='team'&&u.dept===dept.name).length;
    const tasks=S().tasks.filter(t=>t.dept===dept.name).length;
    const color=departmentColor(dept.name);
    return `<div class="row-item">
      <span class="avatar" style="background:${color.fg}">${esc(dept.name).slice(0,2).toUpperCase()}</span>
      <div style="flex:1"><b style="font-size:14px">${esc(dept.name)}</b><div class="muted small">${people} team member${people===1?'':'s'} · ${tasks} task${tasks===1?'':'s'}</div></div>
      <span class="tag-dep" style="background:${color.bg};color:${color.fg}">${esc(dept.name)}</span>
      <div class="member-actions"><button class="btn-ghost small" data-edit-department="${dept.id}">✏️ Edit</button><button class="btn-ghost small" data-delete-department="${dept.id}">🗑️</button></div>
    </div>`;
  }).join('');
  return `<div class="section-head"><p class="muted">Create the departments used for team assignments, task routing, and reporting.</p><button class="btn small" data-add-department>+ Add department</button></div><div class="list">${rows||'<div class="empty"><div class="e-ic">🏢</div>No departments yet</div>'}</div>`;
}

function openDepartmentForm(departmentId=null){
  const department=departmentId?departments().find(d=>d.id===departmentId):null;
  const modal=document.createElement('div');modal.className='modal-scrim';
  modal.innerHTML=`<div class="modal"><div class="modal-head"><h2>${department?'Edit':'Add'} department</h2><button class="btn-ghost" data-close>✕</button></div><div class="modal-body"><div class="field"><label>Name <span class="req">*</span></label><input id="dept-name" maxlength="80" value="${esc(department?.name||'')}" placeholder="e.g. Digital Marketing"></div><div class="field"><label>Colour</label><input id="dept-color" type="color" value="${department?.color||'#3a6ea5'}"></div></div><div class="modal-foot"><button class="btn-ghost" data-close>Cancel</button><button class="btn" id="dept-save">${department?'Save changes':'Create department'}</button></div></div>`;
  $('#modal-host').appendChild(modal);modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.remove());modal.onclick=e=>{if(e.target===modal)modal.remove();};
  $('#dept-save',modal).onclick=async()=>{
    const name=$('#dept-name',modal).value.trim(),color=$('#dept-color',modal).value;
    if(!name){toast('Enter a department name');return;}
    if(departments().some(d=>d.id!==departmentId&&d.name.toLowerCase()===name.toLowerCase())){toast('That department already exists');return;}
    const oldName=department?.name,added=department?null:{id:'dept_'+Math.random().toString(36).slice(2,11),name,color};
    if(department){department.name=name;department.color=color;S().users.forEach(u=>{if(u.dept===oldName)u.dept=name;});S().tasks.forEach(t=>{if(t.dept===oldName)t.dept=name;});}else departments().push(added);
    try{await Store.save();modal.remove();render();toast(department?'✅ Department updated':'✅ Department created');}catch(error){await Store.load();render();toast(error.message);}
  };
}

async function deleteDepartment(departmentId){
  const department=departments().find(d=>d.id===departmentId);if(!department)return;
  const people=S().users.filter(u=>u.role==='team'&&u.dept===department.name).length,tasks=S().tasks.filter(t=>t.dept===department.name).length;
  if(people){toast(`Cannot delete: ${people} team member${people===1?' is':'s are'} assigned to this department`);return;}
  const fallback=departments().find(d=>d.id!==departmentId);
  const moveNote=tasks?` ${tasks} existing task(s) will move to ${fallback?.name||'General'}.`:'';
  if(!confirm(`Delete the ${department.name} department?${moveNote}`))return;
  S().tasks.forEach(t=>{if(t.dept===department.name)t.dept=fallback?.name||'General';});
  S().departments=departments().filter(d=>d.id!==departmentId);
  try{await Store.save();render();toast('🗑️ Department deleted');}catch(error){await Store.load();render();toast(error.message);}
}

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
  return `<div class="section-head"><p class="muted">Each person owns their queue — accountability is explicit. Admins assign new work directly to a team member.</p><button class="btn small" data-add-member>+ Add team member</button></div><div class="list">${rows || '<div class="empty"><div class="e-ic">👥</div>No team members yet</div>'}</div>`;
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
      <div class="field"><label>Department <span class="req">*</span></label><select id="tm-dept">${departments().map(d=>`<option value="${esc(d.name)}" ${member?.dept===d.name?'selected':''}>${esc(d.name)}</option>`).join('')}</select></div>
    </div><div class="modal-foot"><button class="btn-ghost" data-close>Cancel</button><button class="btn" id="tm-save">${member?'Save changes':'Add member'}</button></div></div>`;
  $('#modal-host').appendChild(modal);
  modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.remove());
  modal.onclick=e=>{if(e.target===modal)modal.remove();};
  $('#tm-save').onclick=async()=>{
    const name=$('#tm-name').value.trim(), email=$('#tm-email').value.trim(), phone=$('#tm-phone').value.trim(), dept=$('#tm-dept').value;
    if(!name || !email || !phone || !dept){ toast('Name, email, phone and department are required'); return; }
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ toast('Enter a valid email address'); return; }
    if(S().users.some(u=>u.id!==memberId && u.email && u.email.toLowerCase()===email.toLowerCase())){ toast('That email is already in use'); return; }
    const previous = member ? {...member} : null;
    const added = member ? null : {id:'u_'+Math.random().toString(36).slice(2,9),name,email,phone,dept,role:'team',color:departmentColor(dept).fg};
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
      <p class="muted small" style="margin-bottom:20px">Tell us what you need. An admin will review it and assign it to a team member.</p>
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
async function sendMessage(clientId, taskId, text, voice){
  if (!text && !voice) return;
  const msg = { id:Math.random().toString(36).slice(2,9), clientId, taskId:taskId||null, fromId:session.id, fromRole:session.role==='client'?'client':'team', text:text||'', voice:voice||null, at:Date.now() };
  S().messages.push(msg); Store.save();
  const c = clientById(clientId);
  if (session.role==='client'){
    const clientName=c?.name||'Client',company=c?.company||'No client';
    logActivity(`${clientName} (${company}) sent a ${voice?'voice note':'message'}`,'msg');
    Notify.both({ phone:'+91 agency-team', email:'team@antrajaal.com',
      waText:`💬 New message from ${clientName} (${company}): "${voice?'🎤 voice note':text}"`,
      emailText:`Subject: New message from ${company}` });
  } else {
    try {
      await Store.sendChatEmail({clientId,taskId:taskId||null,text:text||null,hasVoice:!!voice,attachmentNames:[]});
      toast(`✉️ Email sent to ${c?.email||'client'}`);
    } catch (error) {
      toast(error.message);
    }
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
  const due = $('#req-due').value ? new Date($('#req-due').value).getTime() : Date.now()+86400000;
  const task = { id:Math.random().toString(36).slice(2,9), projectId:null, clientId:session.clientId, title, desc, dept, ownerId:null, status:'new', priority:$('#req-prio').value, createdAt:Date.now(), stageAt:Date.now(), dueDate:due, recurring:null, attachments:pendingAttachments };
  S().tasks.push(task);
  if (pendingVoice){ sendMessageRaw(session.clientId, task.id, '', pendingVoice); }
  Store.save();
  const c=clientById(session.clientId);
  const clientName=c?.name||'Client',company=c?.company||'No client';
  logActivity(`New brief from ${company} awaiting assignment`,'brief');
  // notify agency
  Notify.both({ phone:'+91 agency-team', email:'team@antrajaal.com',
    waText:`📥 New request from ${clientName} (${company}): "${title}" → awaiting assignment`,
    emailText:`Subject: New brief — ${title}` });
  // confirm to client
  Notify.both({ phone:c.phone, email:c.email,
    waText:`✅ Hi ${c.name}, we received "${title}". An admin will assign it shortly.`,
    emailText:`Subject: We got your request — ${title}` });
  pendingVoice=null;
  pendingAttachments=[];
  toast('✅ Request sent — awaiting assignment');
  go('my-requests');
}
function sendMessageRaw(clientId, taskId, text, voice){
  S().messages.push({ id:Math.random().toString(36).slice(2,9), clientId, taskId, fromId:session.id, fromRole:'client', text, voice, at:Date.now() });
}

/* ============================================================
   TEAM MEMBER TASK CREATION
============================================================ */
function openTeamTaskForm(){
  if(session.role!=='team')return;
  const clientOpts=`<option value="">No client</option>`+S().clients.map(c=>`<option value="${c.id}">${esc(c.company)}</option>`).join('');
  const projectOpts=`<option value="">No project</option>`+(S().projects||[]).map(p=>`<option value="${p.id}" data-client-id="${p.clientId||''}">${esc(p.name)}${p.clientId?` · ${esc(clientById(p.clientId)?.company||'No client')}`:''}</option>`).join('');
  const modal=document.createElement('div');modal.className='modal-scrim';
  modal.innerHTML=`<div class="modal project-modal"><div class="modal-head"><div><h2>Add task</h2><p class="muted small">Create a task and assign it to yourself or an admin.</p></div><button class="btn-ghost" data-close>✕</button></div><div class="modal-body"><div class="form-row"><div class="field"><label>Client</label><select id="team-task-client">${clientOpts}</select></div><div class="field"><label>Project</label><select id="team-task-project">${projectOpts}</select></div></div><div id="team-task-fields">${projectTaskRow(0)}</div></div><div class="modal-foot"><button class="btn-ghost" data-close>Cancel</button><button class="btn" id="save-team-task">Create task</button></div></div>`;
  $('#modal-host').appendChild(modal);const close=()=>modal.remove();modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=close);modal.onclick=e=>{if(e.target===modal)close();};
  const row=$('[data-project-task]',modal);$('[data-remove-project-task]',row)?.remove();const owner=$('[data-pt-owner]',row);[...owner.options].forEach(option=>{const user=userById(option.value);if(user&&user.role!=='admin'&&user.id!==session.id)option.remove();});owner.value=session.id;bindProjectTaskFiles($('#team-task-fields',modal));
  $('#team-task-project',modal).onchange=e=>{const option=e.target.selectedOptions[0];if(option?.dataset.clientId)$('#team-task-client',modal).value=option.dataset.clientId;};
  $('#save-team-task',modal).onclick=async()=>{
    const title=$('[data-pt-title]',row).value.trim(),assignee=userById(owner.value);if(!title){toast('Enter a task title');return;}if(!assignee){toast('Select yourself or an admin');return;}if(row._reading>0){toast('Please wait for attachments to finish loading');return;}
    const now=Date.now();
    const projectId=$('#team-task-project',modal).value||null,linkedProject=projectById(projectId);
    const dueInput=$('[data-pt-due]',row).value;
    const dept=$('[data-pt-dept]',row).value;if(!dept){toast('Select a department');return;}
    const task={id:'t_'+Math.random().toString(36).slice(2,9),projectId,clientId:linkedProject?.clientId||$('#team-task-client',modal).value||null,title,desc:$('[data-pt-desc]',row).value.trim(),dept,ownerId:assignee.id,status:'todo',priority:$('[data-pt-priority]',row).value,progress:$('[data-pt-progress]',row).value,createdAt:now,stageAt:now,dueDate:dueInput?new Date(dueInput).getTime():null,recurring:null,attachments:row._attachments||[]};
    S().tasks.push(task);try{await Store.save();logActivity(`${session.name} created "${title}" and assigned it to ${assignee.name}`,'brief');close();render();buildNav();toast(`✅ Task assigned to ${assignee.name}`);}catch(error){await Store.load();render();toast(error.message);}
  };
}

/* ============================================================
   TASK MODAL
============================================================ */
function openTask(id){
  const t = S().tasks.find(x=>x.id===id); if(!t) return;
  const c=clientById(t.clientId);
  const canManage = session.role==='admin' || (session.role==='team' && t.ownerId===session.id);
  const msgs = S().messages.filter(m=>m.taskId===t.id).sort((a,b)=>a.at-b.at);
  const teamOpts = `<option value="">Unassigned</option>`+assignableStaff().map(u=>`<option value="${u.id}" ${u.id===t.ownerId?'selected':''}>${assigneeLabel(u)}</option>`).join('');
  const clientOpts=`<option value="" ${t.clientId?'':'selected'}>No client</option>`+S().clients.map(client=>`<option value="${client.id}" ${client.id===t.clientId?'selected':''}>${esc(client.company)}</option>`).join('');
  const projectOpts=`<option value="" ${t.projectId?'':'selected'}>No project</option>`+(S().projects||[]).map(project=>`<option value="${project.id}" data-client-id="${project.clientId||''}" ${project.id===t.projectId?'selected':''}>${esc(project.name)}</option>`).join('');
  const lvl=andonLevel(t);
  const staffControls = canManage ? `
    <div class="field"><label>Task title</label><input id="m-title" value="${esc(t.title)}"></div>
    <div class="field"><label>Description</label><textarea id="m-desc">${esc(t.desc||'')}</textarea></div>
    ${session.role==='admin'?`<div class="form-row"><div class="field"><label>Client</label><select id="m-client">${clientOpts}</select></div><div class="field"><label>Project</label><select id="m-project">${projectOpts}</select></div></div>`:''}
    <div class="form-row" style="margin-top:8px">
      <div class="field"><label>Status / stage</label>
        <select id="m-status">${COLS.map(col=>`<option value="${col.id}" ${t.status===col.id?'selected':''}>${col.label}</option>`).concat(`<option value="blocked" ${t.status==='blocked'?'selected':''}>⛔ Blocked</option>`).join('')}</select></div>
      <div class="field"><label>Owner (accountable)</label>${session.role==='admin'?`<select id="m-owner">${teamOpts}</select>`:`<div class="assignment-note compact">${avatar(session)}<div><b>${esc(session.name)}</b><span>Assigned to you</span></div></div>`}</div>
    </div>
    <div class="form-row">
      <div class="field"><label>Priority</label><select id="m-prio">${['low','med','high'].map(p=>`<option ${t.priority===p?'selected':''}>${p}</option>`).join('')}</select></div>
      <div class="field"><label>${session.role==='admin'?'Department':'Due date'}</label>${session.role==='admin'?`<select id="m-dept">${departments().map(d=>`<option value="${esc(d.name)}" ${t.dept===d.name?'selected':''}>${esc(d.name)}</option>`).join('')}</select>`:`<input id="m-due" type="date" value="${t.dueDate?new Date(t.dueDate).toISOString().slice(0,10):''}">`}</div>
    </div><div class="field"><label>Progress</label><select id="m-progress">${taskProgressOptions(t.progress)}</select></div>` : '';
  const modal = document.createElement('div');
  modal.className='modal-scrim';
  modal.innerHTML = `<div class="modal">
    <div class="modal-head">
      <div style="display:flex;align-items:center;gap:10px">${ACTIVE.includes(t.status)?`<span class="light ${lvl}"></span>`:''}<h2>${esc(t.title)}</h2></div>
      <button class="btn-ghost" data-close>✕</button>
    </div>
    <div class="modal-body">
      <div class="chips" style="margin-bottom:14px">
        <span class="chip" style="cursor:default">📁 ${esc(c?.company||'No client')}</span>
        <span class="chip" style="cursor:default">${t.dept}</span>
        <span class="chip" style="cursor:default">⏱ ${ACTIVE.includes(t.status)?fmtElapsed(t)+' in stage':'—'}</span>
        ${t.recurring?`<span class="chip" style="cursor:default">🔁 ${t.recurring.replaceAll('_',' ')}</span>`:''}
      </div>
      ${canManage?'':`<p style="margin-bottom:6px">${esc(t.desc)||'<span class="muted">No description</span>'}</p>`}
      ${renderTaskAttachments(t.attachments)}
      ${staffControls}
      <div class="divider"></div>
      <h3 style="font-size:14px;margin-bottom:10px">💬 Conversation on this task</h3>
      ${renderChat(msgs)}
      ${composerHTML(t.clientId, t.id)}
    </div>
    ${canManage?`<div class="modal-foot"><button class="btn-ghost btn-delete-task" data-delete-task="${t.id}">Delete task</button><button class="btn-ghost" data-close>Close</button><button class="btn" data-save-task="${t.id}">Save changes</button></div>`:'<div class="modal-foot"><button class="btn" data-close>Close</button></div>'}
  </div>`;
  $('#modal-host').appendChild(modal);
  modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.remove());
  modal.onclick=e=>{ if(e.target===modal) modal.remove(); };
  if(session.role==='admin')$('#m-project',modal).onchange=e=>{const clientId=e.target.selectedOptions[0]?.dataset.clientId;if(clientId)$('#m-client',modal).value=clientId;};
  bindComposer(modal);
  const saveBtn = modal.querySelector('[data-save-task]');
  if (saveBtn) saveBtn.onclick=async()=>{
    const ns=$('#m-status').value;
    if (ns!==t.status){ t.stageAt=Date.now(); logActivity(`${session.name} moved "${t.title}" to ${ns.replace('_',' ')}`,'move');
      if (ns==='done'&&c){ Notify.both({phone:c.phone,email:c.email,waText:`🎉 Good news ${c.name}! "${t.title}" is completed. Please review.`,emailText:`Subject: Completed — ${t.title}`}); }
    }
    t.title=$('#m-title').value.trim();if(!t.title){toast('Task title is required');return;}t.desc=$('#m-desc').value.trim();t.status=ns;t.priority=$('#m-prio').value;t.progress=$('#m-progress').value;
    if(session.role==='admin'){const projectId=$('#m-project',modal).value||null,project=projectById(projectId);t.projectId=projectId;t.clientId=project?.clientId||$('#m-client',modal).value||null;t.ownerId=$('#m-owner').value||null;t.dept=$('#m-dept').value;}else{t.ownerId=session.id;t.dept=session.dept;t.dueDate=$('#m-due').value?new Date($('#m-due').value).getTime():null;}
    try{await Store.save();modal.remove();render();buildNav();toast('✅ Task updated');}catch(error){await Store.load();render();toast(error.message);}
  };
  const deleteBtn=modal.querySelector('[data-delete-task]');if(deleteBtn)deleteBtn.onclick=()=>deleteTask(t.id,modal);
}

async function deleteTask(taskId,modal=null){
  const task=S().tasks.find(t=>t.id===taskId);if(!task)return;
  if(session.role!=='admin' && !(session.role==='team'&&task.ownerId===session.id))return;
  if(!confirm(`Delete "${task.title}"? This cannot be undone.`))return;
  S().tasks=S().tasks.filter(t=>t.id!==taskId);S().messages.forEach(m=>{if(m.taskId===taskId)m.taskId=null;});
  try{await Store.save();modal?.remove();render();buildNav();toast('🗑️ Task deleted');}catch(error){await Store.load();render();toast(error.message);}
}

/* ============================================================
   TASKS
============================================================ */
function viewTasks(){
  const all=session.role==='team'?S().tasks.filter(t=>t.ownerId===session.id):S().tasks,pageSize=10,totalPages=Math.max(1,Math.ceil(all.length/pageSize));tasksPage=Math.min(Math.max(1,tasksPage),totalPages);
  const cards=all.slice((tasksPage-1)*pageSize,tasksPage*pageSize).map(t=>{const client=clientById(t.clientId),project=projectById(t.projectId),owner=userById(t.ownerId),completed=t.status==='done'||t.progress==='completed';return `<article class="task-card ${completed?'task-row-completed':''}" data-task="${t.id}">
    <div class="task-card-head"><span class="status-pill">${taskProgressLabel(t.progress)}</span><div class="project-actions"><button class="project-menu-btn" data-task-menu="${t.id}" aria-label="Task actions" aria-expanded="false">⋮</button><div class="project-menu hidden" data-task-menu-popup="${t.id}">${completed?'<button disabled><span>✓</span>Completed</button>':`<button data-complete-task="${t.id}"><span>✓</span>Mark completed</button>`}<button data-edit-task="${t.id}"><span>✏️</span>Edit task</button><button class="danger" data-delete-task="${t.id}"><span>🗑️</span>Delete task</button></div></div></div>
    <div class="project-client">${esc(client?.company||'No client')}</div><h3>${esc(t.title)}</h3>
    <div class="task-project"><span>📌</span><div><small>Project</small><b>${esc(project?.name||'Standalone task')}</b></div></div>
    <p>${esc(t.desc||'No task description')}</p>
    ${t.dueDate&&!completed?`<div class="task-due-countdown ${t.dueDate<Date.now()?'overdue':''}" data-due-countdown="${t.dueDate}">⏳ ${dueCountdown(t.dueDate)}</div>`:''}
    <div class="task-card-foot"><span>${owner?avatar(owner):''}<span><small>Assigned to</small><b>${owner?esc(owner.name):'Unassigned'}</b></span></span>${t.dueDate?`<time><small>Due</small><b>${new Date(t.dueDate).toLocaleDateString()}</b></time>`:''}</div>
  </article>`;}).join('');
  const pagination=totalPages>1?`<div class="section-head" style="margin-top:14px"><button class="btn-ghost small" data-tasks-page="${tasksPage-1}" ${tasksPage===1?'disabled':''}>← Previous</button><span class="muted small">Page ${tasksPage} of ${totalPages} · ${all.length} tasks</span><button class="btn-ghost small" data-tasks-page="${tasksPage+1}" ${tasksPage===totalPages?'disabled':''}>Next →</button></div>`:'';
  return `<div class="section-head"><p class="muted">${session.role==='team'?'View and complete tasks assigned to you.':'Create and manage individual tasks across clients and projects.'}</p><button class="btn" ${session.role==='team'?'data-add-team-task':'data-new-task'}>+ New task</button></div><div class="task-card-grid">${cards||'<div class="empty"><div class="e-ic">✅</div>No tasks yet</div>'}</div>${pagination}`;
}

async function completeTask(taskId){
  const task=S().tasks.find(t=>t.id===taskId);if(!task)return;
  if(session.role!=='admin'&&!(session.role==='team'&&task.ownerId===session.id))return;
  if(task.status==='done'||task.progress==='completed')return;
  const previous={status:task.status,progress:task.progress,stageAt:task.stageAt};
  task.status='done';task.progress='completed';task.stageAt=Date.now();
  try{await Store.save();render();buildNav();logActivity(`${session.name} completed "${task.title}"`,'move');toast('✅ Task completed');}catch(error){Object.assign(task,previous);render();toast(error.message);}
}

function openNewTask(){
  const clientOpts=`<option value="">No client</option>`+S().clients.map(c=>`<option value="${c.id}">${esc(c.company)}</option>`).join('');
  const projectOpts=`<option value="">No project</option>`+(S().projects||[]).map(p=>`<option value="${p.id}" data-client-id="${p.clientId||''}">${esc(p.name)}${p.clientId?` · ${esc(clientById(p.clientId)?.company||'No client')}`:''}</option>`).join('');
  const modal=document.createElement('div');modal.className='modal-scrim';
  modal.innerHTML=`<div class="modal project-modal"><div class="modal-head"><div><h2>New task</h2><p class="muted small">Create a task and assign clear ownership.</p></div><button class="btn-ghost" data-close>✕</button></div><div class="modal-body"><div class="form-row"><div class="field"><label>Client</label><select id="task-client">${clientOpts}</select></div><div class="field"><label>Project</label><select id="task-project">${projectOpts}</select></div></div><div id="new-task-fields">${projectTaskRow(0)}</div></div><div class="modal-foot"><button class="btn-ghost" data-close>Cancel</button><button class="btn" id="create-task">Create task</button></div></div>`;
  $('#modal-host').appendChild(modal);const close=()=>modal.remove();modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=close);modal.onclick=e=>{if(e.target===modal)close();};
  const row=$('[data-project-task]',modal);$('[data-remove-project-task]',row)?.remove();bindProjectTaskFiles($('#new-task-fields',modal));
  $('#task-project',modal).onchange=e=>{const clientId=e.target.selectedOptions[0]?.dataset.clientId;if(clientId)$('#task-client',modal).value=clientId;};
  $('#create-task',modal).onclick=async()=>{const title=$('[data-pt-title]',row).value.trim(),owner=userById($('[data-pt-owner]',row).value),dept=$('[data-pt-dept]',row).value;if(!title){toast('Enter a task title');return;}if(!owner){toast('Select a team member or admin');return;}if(!dept){toast('Select a department');return;}if(row._reading>0){toast('Please wait for attachments to finish loading');return;}const projectId=$('#task-project',modal).value||null,project=projectById(projectId),clientId=project?.clientId||$('#task-client',modal).value||null,now=Date.now(),dueInput=$('[data-pt-due]',row).value;S().tasks.push({id:'t_'+Math.random().toString(36).slice(2,9),projectId,clientId,title,desc:$('[data-pt-desc]',row).value.trim(),dept,ownerId:owner.id,status:'todo',priority:$('[data-pt-priority]',row).value,progress:$('[data-pt-progress]',row).value,createdAt:now,stageAt:now,dueDate:dueInput?new Date(dueInput).getTime():null,recurring:null,attachments:row._attachments||[]});try{await Store.save();close();go('tasks');toast('✅ Task created');}catch(error){await Store.load();render();toast(error.message);}};
}


/* ============================================================
   NEW RECURRING MODAL
============================================================ */
function openNewRecurring(taskId=null){
  if(session.role!=='admin')return;
  const task=taskId?S().tasks.find(t=>t.id===taskId&&t.recurring):null;if(taskId&&!task)return;
  const editing=!!task;
  const clientOpts=`<option value="">No client</option>`+S().clients.map(c=>`<option value="${c.id}" ${task?.clientId===c.id?'selected':''}>${esc(c.company)}</option>`).join('');
  const projectOpts=`<option value="">No project</option>`+(S().projects||[]).map(p=>`<option value="${p.id}" data-client-id="${p.clientId||''}" ${task?.projectId===p.id?'selected':''}>${esc(p.name)}${p.clientId?` · ${esc(clientById(p.clientId)?.company||'No client')}`:''}</option>`).join('');
  const memberOpts=assignableStaff().map(u=>`<option value="${u.id}" ${task?.ownerId===u.id?'selected':''}>${assigneeLabel(u)}</option>`).join('');
  const modal=document.createElement('div'); modal.className='modal-scrim';
  modal.innerHTML=`<div class="modal"><div class="modal-head"><h2>${editing?'Edit':'New'} repeating task</h2><button class="btn-ghost" data-close>✕</button></div>
    <div class="modal-body">
      <div class="field"><label>Title</label><input id="rc-title" value="${esc(task?.title||'')}" placeholder="e.g. Weekly Instagram carousel"></div>
      <div class="form-row"><div class="field"><label>Client</label><select id="rc-client">${clientOpts}</select></div><div class="field"><label>Project</label><select id="rc-project">${projectOpts}</select></div></div>
      <div class="form-row">
        <div class="field"><label>Assign to</label><select id="rc-owner"><option value="">Select team member or admin</option>${memberOpts}</select></div>
        <div class="field"><label>Repeats</label><select id="rc-freq"><option value="daily" ${task?.recurring==='daily'?'selected':''}>Daily</option><option value="alternate_days" ${task?.recurring==='alternate_days'?'selected':''}>Alternate Days</option><option value="weekly" ${!task||task.recurring==='weekly'?'selected':''}>Weekly</option><option value="monthly" ${task?.recurring==='monthly'?'selected':''}>Monthly</option></select></div>
      </div>
      <div class="form-row">
        <div class="field"><label>Due date</label><input id="rc-due" type="date" value="${task?.dueDate?new Date(task.dueDate).toISOString().slice(0,10):''}"></div>
        <div class="field"><label>Progress</label><select id="rc-progress">${taskProgressOptions(task?.progress)}</select></div>
      </div>
    </div>
    <div class="modal-foot">${editing?`<button class="btn-ghost btn-delete-task" data-delete-recurring="${task.id}">Delete</button>`:''}<button class="btn-ghost" data-close>Cancel</button><button class="btn" id="rc-save">${editing?'Save changes':'Create'}</button></div></div>`;
  $('#modal-host').appendChild(modal);
  modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.remove());
  modal.onclick=e=>{if(e.target===modal)modal.remove();};
  $('#rc-project',modal).onchange=e=>{const clientId=e.target.selectedOptions[0]?.dataset.clientId;if(clientId)$('#rc-client',modal).value=clientId;};
  $('#rc-save').onclick=async()=>{
    const title=$('#rc-title').value.trim(); if(!title){toast('Add a title');return;}
    const owner=userById($('#rc-owner').value); if(!owner){toast('Select a team member or admin');return;}
    const projectId=$('#rc-project',modal).value||null,project=projectById(projectId),clientId=project?.clientId||$('#rc-client',modal).value||null;
    const frequency=$('#rc-freq').value,now=Date.now();let nextRecurrenceAt=task?.nextRecurrenceAt;
    if(!editing||frequency!==task.recurring||!nextRecurrenceAt){const nextDate=new Date(now);if(frequency==='monthly'){const day=nextDate.getDate();nextDate.setDate(1);nextDate.setMonth(nextDate.getMonth()+1);nextDate.setDate(Math.min(day,new Date(nextDate.getFullYear(),nextDate.getMonth()+1,0).getDate()));}else nextDate.setDate(nextDate.getDate()+({daily:1,alternate_days:2,weekly:7}[frequency]));nextRecurrenceAt=nextDate.getTime();}
    const dueInput=$('#rc-due',modal).value;
    const values={projectId,clientId,title,dept:owner.dept||'General',ownerId:owner.id,dueDate:dueInput?new Date(dueInput).getTime():null,progress:$('#rc-progress',modal).value,recurring:frequency,nextRecurrenceAt};
    if(editing)Object.assign(task,values);else S().tasks.push({id:Math.random().toString(36).slice(2,9),...values,desc:'Repeating task',status:'todo',priority:'med',progress:'just_started',createdAt:now,stageAt:now});
    try{await Store.save();modal.remove();render();toast(editing?'✅ Repeating task updated':'🔁 Repeating task created');}catch(error){await Store.load();render();toast(error.message);}
  };
  const deleteBtn=modal.querySelector('[data-delete-recurring]');if(deleteBtn)deleteBtn.onclick=()=>deleteTask(task.id,modal);
}

/* ============================================================
   BIND VIEW EVENTS
============================================================ */
function bindView(){
  $$('[data-go]').forEach(e=>e.onclick=()=>go(e.dataset.go));
  $$('[data-client]').forEach(e=>e.onclick=()=>go('client-folder', e.dataset.client));
  $$('[data-task]').forEach(e=>e.onclick=(ev)=>{ if(ev.target.closest('.composer'))return; openTask(e.dataset.task); });
  const sr=$('#submit-req'); if(sr) sr.onclick=submitRequest;
  const ac=$('[data-add-client]');if(ac)ac.onclick=()=>openClientForm();
  $$('[data-client-menu]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();const popup=$(`[data-client-menu-popup="${btn.dataset.clientMenu}"]`);$$('.project-menu').forEach(menu=>{if(menu!==popup)menu.classList.add('hidden');});popup.classList.toggle('hidden');btn.setAttribute('aria-expanded',String(!popup.classList.contains('hidden')));});
  $$('[data-edit-client]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();openClientForm(btn.dataset.editClient);});
  $$('[data-delete-client]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();deleteClient(btn.dataset.deleteClient);});
  const att=$('[data-add-team-task]');if(att)att.onclick=openTeamTaskForm;
  const ap=$('[data-add-project]'); if(ap) ap.onclick=()=>openProjectForm();
  $$('[data-project-menu]').forEach(btn=>btn.onclick=e=>{
    e.stopPropagation(); const popup=$(`[data-project-menu-popup="${btn.dataset.projectMenu}"]`);
    $$('.project-menu').forEach(menu=>{if(menu!==popup)menu.classList.add('hidden');});
    popup.classList.toggle('hidden'); btn.setAttribute('aria-expanded',String(!popup.classList.contains('hidden')));
  });
  $$('[data-edit-project]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();openProjectForm(btn.dataset.editProject);});
  $$('[data-delete-project]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();deleteProject(btn.dataset.deleteProject);});
  const nr=$('[data-new-recurring]'); if(nr) nr.onclick=()=>openNewRecurring();
  $$('[data-edit-recurring]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();openNewRecurring(btn.dataset.editRecurring);});
  $$('[data-delete-recurring]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();deleteTask(btn.dataset.deleteRecurring);});
  const nt=$('[data-new-task]'); if(nt) nt.onclick=openNewTask;
  $$('[data-task-menu]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();const popup=$(`[data-task-menu-popup="${btn.dataset.taskMenu}"]`);$$('.project-menu').forEach(menu=>{if(menu!==popup)menu.classList.add('hidden');});popup.classList.toggle('hidden');btn.setAttribute('aria-expanded',String(!popup.classList.contains('hidden')));});
  $$('[data-complete-task]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();completeTask(btn.dataset.completeTask);});
  $$('[data-edit-task]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();openTask(btn.dataset.editTask);});
  $$('[data-delete-task]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();deleteTask(btn.dataset.deleteTask);});
  $$('[data-tasks-page]').forEach(btn=>btn.onclick=()=>{if(btn.disabled)return;tasksPage=Number(btn.dataset.tasksPage);render();});
  const am=$('[data-add-member]'); if(am) am.onclick=()=>openTeamMember();
  $$('[data-edit-member]').forEach(b=>b.onclick=()=>openTeamMember(b.dataset.editMember));
  $$('[data-delete-member]').forEach(b=>b.onclick=()=>deleteTeamMember(b.dataset.deleteMember));
  const ad=$('[data-add-department]');if(ad)ad.onclick=()=>openDepartmentForm();
  $$('[data-edit-department]').forEach(b=>b.onclick=()=>openDepartmentForm(b.dataset.editDepartment));
  $$('[data-delete-department]').forEach(b=>b.onclick=()=>deleteDepartment(b.dataset.deleteDepartment));
  // settings
  const st=$('#save-thresh'); if(st) st.onclick=()=>{ S().settings.amberMin=+$('#set-amber').value||15; S().settings.redMin=+$('#set-red').value||25; Store.save(); toast('✅ Thresholds saved'); buildNav(); };
  const rd=$('#reset-data'); if(rd) rd.onclick=async()=>{ await Store.reset(); toast('🧹 Demo data reset'); render(); buildNav(); };
  // voice box on new request
  const vb=$('#req-voice-box');
  if (vb){ renderReqVoice(vb); }
  const fileInput=$('#req-files');
  if (fileInput) fileInput.onchange=()=>addRequestFiles(fileInput.files);
  // composers in view
  bindComposer($('#view'));
}

document.addEventListener('click', e=>{
  if(e.target.closest('.project-actions'))return;
  $$('.project-menu').forEach(menu=>menu.classList.add('hidden'));
  $$('[data-project-menu]').forEach(btn=>btn.setAttribute('aria-expanded','false'));
});
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
  $$('[data-due-countdown]').forEach(el=>{const dueDate=Number(el.dataset.dueCountdown);el.textContent='⏳ '+dueCountdown(dueDate);el.classList.toggle('overdue',dueDate<Date.now());});
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
    session = null;
    document.body.innerHTML = `<div style="padding:40px;font-family:sans-serif"><h2>Karya could not connect to the server</h2><p>${esc(error.message)}</p></div>`;
    return;
  }
  renderLogin();
  const saved=await Store.currentSession();
  if (saved && userById(saved)) signIn(saved);
})();
