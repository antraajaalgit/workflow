/* ============ NAGARE · Data Store (Laravel + MySQL) ============ */

const DEFAULT_DEPARTMENTS = ['Design', 'Development', 'Content', 'Marketing', 'SEO'];

const DEPT_COLOR = {
  Design:      { bg:'rgba(122,92,62,.13)',  fg:'#7a5c3e' },
  Development: { bg:'rgba(58,110,165,.13)',  fg:'#3a6ea5' },
  Content:     { bg:'rgba(201,162,95,.18)',  fg:'#a97e2e' },
  Marketing:   { bg:'rgba(217,74,61,.12)',   fg:'#d94a3d' },
  SEO:         { bg:'rgba(63,163,77,.14)',   fg:'#3fa34d' },
};

/* Auto-delegation rules: keyword -> department (first match wins) */
const DEFAULT_RULES = [
  { kw: 'logo,brand,poster,banner,design,graphic,ui,ux,mockup,creative', dept: 'Design' },
  { kw: 'website,web,app,bug,code,develop,api,landing page,wordpress,html', dept: 'Development' },
  { kw: 'blog,article,copy,caption,content,write,script,newsletter', dept: 'Content' },
  { kw: 'ad,ads,campaign,promo,social,instagram,facebook,marketing,launch', dept: 'Marketing' },
  { kw: 'seo,keyword,rank,search,backlink,meta,traffic', dept: 'SEO' },
];

const now = () => Date.now();
const mins = (m) => m * 60 * 1000;
const uid = () => Math.random().toString(36).slice(2, 9);

function seed() {
  const team = [
    { id:'u_admin_sales', name:'Sales Admin', role:'admin', dept:null, color:'#7a5c3e' },
    { id:'u_admin_ceo', name:'CEO Admin', role:'admin', dept:null, color:'#c9a25f' },
    { id:'u_admin_agam', name:'Agam Bahri', role:'admin', dept:null, color:'#3a6ea5' },
    { id:'u_riya',  name:'Riya Kapoor', role:'team', dept:'Design', color:'#c9a25f' },
    { id:'u_arjun', name:'Arjun Mehta', role:'team', dept:'Development', color:'#3a6ea5' },
    { id:'u_sara',  name:'Sara Khan',   role:'team', dept:'Content', color:'#3fa34d' },
    { id:'u_dev',   name:'Dev Sharma',  role:'team', dept:'Marketing', color:'#d94a3d' },
    { id:'u_neha',  name:'Neha Rao',    role:'team', dept:'SEO', color:'#8a6d3b' },
  ];
  const clients = [
    { id:'c_lumen', name:'Priya Nair', company:'Lumen Cafe', email:'priya@lumencafe.com', phone:'+91 98xxx xxx01', color:'#7a5c3e' },
    { id:'c_volt',  name:'Karan Shah', company:'Volt Fitness', email:'karan@voltfit.in', phone:'+91 98xxx xxx02', color:'#3a6ea5' },
    { id:'c_bloom', name:'Ananya Das', company:'Bloom Skincare', email:'ananya@bloom.co', phone:'+91 98xxx xxx03', color:'#3fa34d' },
  ];
  const users = [...team, ...clients.map(c => ({ id:c.id, name:c.name, role:'client', clientId:c.id, company:c.company, color:c.color }))];

  const T = now();
  const tasks = [
    { id:uid(), clientId:'c_lumen', title:'New menu poster for winter specials', desc:'Need an A3 poster, warm tones, 6 dishes highlighted.', dept:'Design', ownerId:'u_riya', status:'in_progress', priority:'high', createdAt:T-mins(180), stageAt:T-mins(31), dueDate:T+mins(1440), recurring:null },
    { id:uid(), clientId:'c_lumen', title:'Weekly Instagram carousel', desc:'3 slides on new coffee blend.', dept:'Content', ownerId:'u_sara', status:'review', priority:'med', createdAt:T-mins(300), stageAt:T-mins(12), dueDate:T+mins(600), recurring:'weekly' },
    { id:uid(), clientId:'c_volt', title:'Landing page bug — signup form not submitting', desc:'Form throws error on mobile Safari.', dept:'Development', ownerId:'u_arjun', status:'in_progress', priority:'high', createdAt:T-mins(90), stageAt:T-mins(47), dueDate:T+mins(300), recurring:null },
    { id:uid(), clientId:'c_volt', title:'Google Ads campaign for Jan promo', desc:'Budget ₹20k, target 25-40 fitness audience.', dept:'Marketing', ownerId:'u_dev', status:'todo', priority:'med', createdAt:T-mins(60), stageAt:T-mins(60), dueDate:T+mins(2880), recurring:null },
    { id:uid(), clientId:'c_bloom', title:'SEO audit + keyword plan', desc:'Full audit of bloom.co, top 20 keywords.', dept:'SEO', ownerId:'u_neha', status:'in_progress', priority:'low', createdAt:T-mins(240), stageAt:T-mins(18), dueDate:T+mins(4320), recurring:'monthly' },
    { id:uid(), clientId:'c_bloom', title:'Rebrand logo concepts', desc:'3 directions, minimal, botanical feel.', dept:'Design', ownerId:'u_riya', status:'todo', priority:'med', createdAt:T-mins(120), stageAt:T-mins(120), dueDate:T+mins(5760), recurring:null },
    { id:uid(), clientId:'c_lumen', title:'Blog: "5 winter drinks" article', desc:'800 words, SEO friendly.', dept:'Content', ownerId:'u_sara', status:'done', priority:'low', createdAt:T-mins(600), stageAt:T-mins(200), dueDate:T-mins(60), recurring:null },
    { id:uid(), clientId:'c_volt', title:'Monthly performance report', desc:'Auto-generated every 1st.', dept:'Marketing', ownerId:'u_dev', status:'new', priority:'med', createdAt:T-mins(20), stageAt:T-mins(20), dueDate:T+mins(1440), recurring:'monthly' },
  ];

  const tid = tasks[0].id, tid2 = tasks[2].id;
  const messages = [
    { id:uid(), clientId:'c_lumen', taskId:tid, fromId:'c_lumen', fromRole:'client', text:'Hi team! Can we make the poster feel cozy and warm? Winter vibes 🙂', voice:null, at:T-mins(170) },
    { id:uid(), clientId:'c_lumen', taskId:tid, fromId:'u_riya',  fromRole:'team', text:'Absolutely Priya — starting on warm tones now. Will share a draft by evening.', voice:null, at:T-mins(160) },
    { id:uid(), clientId:'c_lumen', taskId:tid, fromId:'c_lumen', fromRole:'client', text:'Perfect, thank you!', voice:null, at:T-mins(150) },
    { id:uid(), clientId:'c_volt', taskId:tid2, fromId:'c_volt', fromRole:'client', text:'The signup bug is urgent, we are losing leads. Please prioritise 🙏', voice:null, at:T-mins(88) },
    { id:uid(), clientId:'c_volt', taskId:tid2, fromId:'u_arjun', fromRole:'team', text:'On it Karan — reproduced on iOS Safari, fixing the validation now.', voice:null, at:T-mins(80) },
  ];

  const activity = [
    { id:uid(), at:T-mins(20), text:'New brief received from Volt Fitness → awaiting admin assignment', type:'brief' },
    { id:uid(), at:T-mins(47), text:'Arjun moved "Landing page bug" to In Progress', type:'move' },
    { id:uid(), at:T-mins(88), text:'Karan Shah (Volt Fitness) sent a message', type:'msg' },
  ];

  const notifications = [
    { id:uid(), channel:'whatsapp', to:'+91 98xxx xxx02', text:'✅ Your request "Landing page bug" was received. An admin will assign it shortly.', at:T-mins(88) },
    { id:uid(), channel:'email', to:'karan@voltfit.in', text:'Subject: We got your request — Landing page bug', at:T-mins(88) },
  ];

  return {
    departments: DEFAULT_DEPARTMENTS.map(name=>({id:'dept_'+name.toLowerCase(),name,color:DEPT_COLOR[name].fg})),
    users, clients, projects: [], tasks, messages, activity, notifications,
    rules: DEFAULT_RULES,
    settings: { amberMin: 15, redMin: 25 },
  };
}

const Store = {
  data: null,

  taskPayload(values) {
    const fields = {clientId:'client_id',projectId:'project_id',title:'title',desc:'description',dept:'department',ownerId:'owner_id',ownerIds:'owner_ids',status:'status',priority:'priority',progress:'progress',dueDate:'due_date_ms',recurring:'recurring',nextRecurrenceAt:'next_recurrence_at_ms',attachments:'attachments'};
    return Object.fromEntries(Object.entries(fields).filter(([key])=>Object.hasOwn(values,key)).map(([key,column])=>[column,values[key]]));
  },
  taskFromApi(task) {
    return {id:task.id,clientId:task.client_id,projectId:task.project_id,title:task.title,desc:task.description,dept:task.department,
      ownerId:task.owner_id,ownerIds:task.owner_ids,status:task.status,priority:task.priority,progress:task.progress,
      createdAt:task.created_at_ms,stageAt:task.stage_at_ms,dueDate:task.due_date_ms,recurring:task.recurring,
      nextRecurrenceAt:task.next_recurrence_at_ms,attachments:task.attachments||[]};
  },
  async taskJson(url, method, payload) {
    for (let attempt=0; attempt<2; attempt++) {
      const token=document.querySelector('meta[name="csrf-token"]')?.content;
      const headers=this.headers ? this.headers({'Content-Type':'application/json'}) : {Accept:'application/json','Content-Type':'application/json',...(token?{'X-CSRF-TOKEN':token}:{})};
      const response=await fetch(url,{method,credentials:'same-origin',headers,...(payload===undefined?{}:{body:JSON.stringify(payload)})});
      if(response.status===419 && !attempt) {
        const session=this.currentSession ? await this.currentSession() : await (await fetch('/api/session',{credentials:'same-origin',headers:{Accept:'application/json'}})).json();
        if(!session.userId) throw new Error('Your session has expired. Please sign in again.');
        const meta=document.querySelector('meta[name="csrf-token"]');if(meta&&session.csrfToken)meta.content=session.csrfToken;
        continue;
      }
      const result=await response.json().catch(()=>({message:'Unable to save task'}));
      if(!response.ok) {
        const details=Object.values(result.errors||{}).flat().join(' ');
        const error=new Error(details||result.message||'Unable to save task');error.status=response.status;throw error;
      }
      return result;
    }
  },
  taskRequest(method, id, suffix, payload) {
    const source=this.data;
    const body=payload===undefined?undefined:JSON.parse(JSON.stringify(payload));
    const pending=this._saveQueue||Promise.resolve();
    const operation=pending.catch(()=>{}).then(async()=>{
      if(this.data!==source) throw new Error('Application data changed. Reload before saving.');
      const baseline=JSON.parse(JSON.stringify(this._baseline||source));
      if(JSON.stringify(source.tasks)!==JSON.stringify(baseline.tasks)) throw new Error('Save or reload pending task changes before using this action.');
      let result;
      try { result=await this.taskJson('/api/tasks'+(id==null?'':'/'+encodeURIComponent(id))+suffix,method,body); }
      catch(error) {
        if(!error.status || [404,409].includes(error.status)) source._revision=null;
        throw error;
      }
      if(method==='DELETE' ? result.deleted!==true : typeof result.id!=='string') {
        source._revision=null;throw new Error('Unable to confirm task save. Reload before retrying.');
      }
      this._taskGeneration=(this._taskGeneration||0)+1;
      const apply = state => {
        if(method==='DELETE') {
          state.tasks=state.tasks.filter(task=>task.id!==id);
          (state.messages||[]).forEach(message=>{if(message.taskId===id)message.taskId=null;});
        } else {
          const task=this.taskFromApi(result),index=state.tasks.findIndex(item=>item.id===task.id);
          if(index<0)state.tasks.push(task);else state.tasks[index]=task;
        }
      };
      apply(source);apply(baseline);
      // Refresh server effects and revision without performing another write.
      // Preserve unrelated local edits only when the remote field has not also changed.
      try {
        const remote=await this.taskJson('/api/state','GET');
        if(!Array.isArray(remote.tasks)||typeof remote._revision!=='string')throw new Error('Invalid state response');
        let conflict=false;
        for(const key of Object.keys(remote).filter(key=>key!=='_revision')) {
          if(JSON.stringify(source[key])===JSON.stringify(baseline[key])) source[key]=remote[key];
          else if(JSON.stringify(remote[key])!==JSON.stringify(baseline[key])) conflict=true;
        }
        source._revision=conflict?null:remote._revision;
        this._baseline=JSON.parse(JSON.stringify(remote));
        if(conflict) result.syncWarning='Task saved. Other data changed; reload before saving other changes.';
      } catch(error) {
        source._revision=null;
        // Keep the authoritative successful response; never retry a committed POST.
        this._baseline=baseline;
        result.syncWarning='Task saved, but dashboard refresh failed. Reload before saving other changes.';
      }
      return result;
    });
    this._saveQueue=operation;
    return operation;
  },
  createTask(values) {
    const payload=this.taskPayload(values);
    // Non-recurring team tasks must omit admin-only recurrence fields entirely.
    if(payload.recurring==null)delete payload.recurring;
    if(payload.next_recurrence_at_ms==null)delete payload.next_recurrence_at_ms;
    return this.taskRequest('POST',null,'',payload);
  },
  updateTask(id,values) { return this.taskRequest('PATCH',id,'',this.taskPayload(values)); },
  setTaskStatus(id,status) { return this.taskRequest('PATCH',id,'/status',{status}); },
  setTaskProgress(id,progress) { return this.taskRequest('PATCH',id,'/progress',{progress}); },
  setTaskAssignees(id,ownerIds) { return this.taskRequest('PATCH',id,'/assignees',{owner_ids:ownerIds}); },
  deleteTask(id) { return this.taskRequest('DELETE',id,''); },
  async saveTaskChanges(id,values) {
    const task=this.data.tasks.find(task=>task.id===id);
    if(!task) throw new Error('Task not found.');
    const changes=Object.fromEntries(Object.entries(values).filter(([key,value])=>JSON.stringify(value)!==JSON.stringify(task[key]) && !(key==='desc'&&!value&&!task[key])));
    const keys=Object.keys(changes);
    if(!keys.length)return {assignmentMailFailures:[]};
    if(keys.length===1&&keys[0]==='status')return this.setTaskStatus(id,changes.status);
    if(keys.length===1&&keys[0]==='progress')return this.setTaskProgress(id,changes.progress);
    if(keys.every(key=>['ownerId','ownerIds'].includes(key)))return this.setTaskAssignees(id,changes.ownerIds??(changes.ownerId?[changes.ownerId]:[]));
    return this.updateTask(id,changes);
  },
  compoundRequest(url,method,values={}) {
    const source=this.data,body=JSON.parse(JSON.stringify(values));
    const operation=(this._saveQueue||Promise.resolve()).catch(()=>{}).then(async()=>{
      if(this.data!==source)throw new Error('Application data changed. Reload before saving.');
      const baseline=JSON.parse(JSON.stringify(this._baseline||source));
      if(JSON.stringify(source.tasks)!==JSON.stringify(baseline.tasks))throw new Error('Reload pending task changes before using this action.');
      let result;
      try{result=await this.taskJson(url,method,{...body,_revision:body._revision??source._revision});}
      catch(error){if(!error.status||[404,409].includes(error.status))source._revision=null;throw error;}
      const remote=result.state;
      if(!Array.isArray(remote?.tasks)||typeof remote._revision!=='string'){
        source._revision=null;throw new Error('Unable to confirm save. Reload before retrying.');
      }
      this._taskGeneration=(this._taskGeneration||0)+1;
      let conflict=false;
      for(const key of Object.keys(remote).filter(key=>key!=='_revision')){
        if(key==='tasks'||JSON.stringify(source[key])===JSON.stringify(baseline[key]))source[key]=remote[key];
        else if(JSON.stringify(remote[key])!==JSON.stringify(baseline[key]))conflict=true;
      }
      source._revision=conflict?null:remote._revision;
      this._baseline=JSON.parse(JSON.stringify(remote));
      if(conflict)result.syncWarning='Saved. Other data changed; reload before saving other changes.';
      return result;
    });
    this._saveQueue=operation;return operation;
  },
  saveProject(id,values){return this.compoundRequest(id?'/api/projects/'+encodeURIComponent(id)+'/tasks':'/api/projects',id?'PATCH':'POST',values);},
  deleteProject(id){return this.compoundRequest('/api/projects/'+encodeURIComponent(id),'DELETE');},
  saveClient(id,values){return this.compoundRequest('/api/clients'+(id?'/'+encodeURIComponent(id):''),id?'PATCH':'POST',values);},
  deleteClient(id){return this.compoundRequest('/api/clients/'+encodeURIComponent(id),'DELETE');},
  deleteMember(id){return this.compoundRequest('/api/team-members/'+encodeURIComponent(id),'DELETE');},
  saveDepartment(id,values){return this.compoundRequest('/api/departments'+(id?'/'+encodeURIComponent(id):''),id?'PATCH':'POST',values);},
  deleteDepartment(id){return this.compoundRequest('/api/departments/'+encodeURIComponent(id),'DELETE');},
  createBrief(values,voice){return this.compoundRequest('/api/briefs','POST',{task:this.taskPayload(values),voice});},

  async load() {
    await (this._saveQueue||Promise.resolve()).catch(()=>{});
    const response = await fetch('/api/state', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Unable to load application data');
    this.data = await response.json();
    this._baseline = JSON.parse(JSON.stringify(this.data));
    return this.data;
  },
  // Keep legacy whole-state saving, but serialize calls and carry a server revision.
  save() {
    const source = this.data;
    const snapshot = JSON.parse(JSON.stringify(source));
    const generation = this._taskGeneration||0;
    const pending = this._saveQueue || Promise.resolve();
    const operation = pending.catch(() => {}).then(async () => {
      if (this.data !== source || !source?._revision || generation !== (this._taskGeneration||0)) {
        throw new Error('Application data changed. Reload before saving.');
      }
      snapshot._revision = source._revision;
      try {
        let response;
        for (let attempt = 0; attempt < 2; attempt++) {
          const headers = this.headers ? this.headers({'Content-Type':'application/json'}) : {
            Accept:'application/json', 'Content-Type':'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
          };
          response = await fetch('/api/state', {method:'PUT', headers, body:JSON.stringify({...snapshot,tasks:undefined})});
          if (response.status !== 419 || attempt || !this.currentSession) break;
          const session = await this.currentSession();
          if (!session.userId) throw new Error('Your session has expired. Please sign in again.');
        }
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to save application data');
        source._revision = result._revision;
        this._baseline = {...snapshot,_revision:result._revision};
        return result;
      } catch (error) {
        // A failed/uncertain write must not be retried using an older snapshot.
        source._revision = null;
        throw error;
      }
    });
    this._saveQueue = operation;
    return operation;
  },
  async reset() {
    const response = await fetch('/api/state/reset', {
      method: 'POST',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('Unable to reset application data');
    this.data = await response.json();
    this._baseline = JSON.parse(JSON.stringify(this.data));
  },
  async sendChatEmail(payload) {
    const response = await fetch('/api/chat-email', {method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(payload)});
    const data = await response.json();
    if(!response.ok)throw new Error(data.message||'Unable to email the client');
    return data;
  },
  async uploadTeamMemberImage(file) {
    const body=new FormData();body.append('image',file);
    const token=document.querySelector('meta[name="csrf-token"]')?.content;
    const response=await fetch('/api/team-member-image',{method:'POST',headers:{Accept:'application/json',...(token?{'X-CSRF-TOKEN':token}:{})},body});
    const data=await response.json();
    if(!response.ok)throw new Error(data.message||'Unable to upload team member image');
    return data.url;
  },
};
