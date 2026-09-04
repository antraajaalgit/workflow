const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']) {
 const code=fs.readFileSync(file,'utf8');
 const section=(start,end)=>code.slice(code.indexOf(start),code.indexOf(end,code.indexOf(start)+start.length));
 const helpers=section('// Task writes own','async function submitRequest');
 function setup(){
   const calls=[],toasts=[],task={id:'t_test',title:'Original',desc:'Description',ownerId:'one',ownerIds:['one'],status:'todo',progress:'just_started',priority:'med',dept:'General',attachments:[]};
   const user={id:'one',role:'team',name:'One',dept:'General'};
   const state={_revision:'r1',tasks:[task],users:[user],clients:[],projects:[],messages:[]};
   const elements=new Map();
   function element(selector){
     if(!elements.has(selector))elements.set(selector,{value:'',options:[],classList:{toggle(){}},remove(){},appendChild(){},querySelectorAll(){return [];},querySelector:element});
     return elements.get(selector);
   }
   const values={'#req-title':'Brief','#req-desc':'Details','#req-prio':'med','#req-due':'','[data-pt-title]':'New task','[data-pt-desc]':'Details','[data-pt-dept]':'General','[data-pt-owner]':'one','[data-pt-priority]':'med','[data-pt-progress]':'25','#rc-title':'Recurring','#rc-owner':'one','#rc-freq':'weekly','#rc-progress':'50','#m-title':'Edited','#m-desc':'Description','#m-status':'review','#m-prio':'med','#m-progress':'75','#m-owner':'one','#m-dept':'General'};
   Object.entries(values).forEach(([k,v])=>element(k).value=v);
   element('[data-pt-owner]').options=[{value:'one',remove(){}}];
   const store={data:state,taskPayload:values=>values,saveProject:async(id,values)=>{calls.push(['project',id,values]);return {};},createBrief:async(values,voice)=>{calls.push(['brief',values,voice]);return {};},save:()=>{throw Error('Unexpected full-state save');},load:()=>{throw Error('Unexpected reload');},
     createTask:async values=>{calls.push(['create',values]);return {id:'server',assignmentMailFailures:[]};},
     saveTaskChanges:async(id,values)=>{calls.push(['edit',id,values]);return {};},
     deleteTask:async id=>{calls.push(['delete',id]);return {};}};
   for(const method of ['saveClient','saveDepartment','deleteProject','deleteClient','deleteMember','deleteDepartment'])store[method]=async(...args)=>{calls.push([method,...args]);return {};};
   const ctx=vm.createContext({Store:store,S:()=>state,session:{id:'one',role:'admin',clientId:'client',name:'One'},pendingVoice:null,pendingAttachments:[],
     $:element,$$:selector=>selector==='[data-project-task]'?[{dataset:{},_attachments:[]}]:[],document:{createElement:()=>element('modal')},toast:message=>toasts.push(message),render(){},buildNav(){},updateBell(){},go(){},confirm:()=>true,
     clientById:()=>null,projectById:()=>null,userById:id=>state.users.find(u=>u.id===id),assignableStaff:()=>state.users,assigneeLabel:u=>u.name,selectedValues:()=>['one'],isTaskOwner:t=>t.ownerIds.includes('one'),
     projectTaskRow:()=>'',bindProjectTaskFiles(){},passwordField:()=>'',bindPasswordTools(){},esc:v=>v||'',taskProgressOptions:()=>'',departments:()=>[{name:'General'}],andonLevel:()=>'',ACTIVE:['todo'],fmtElapsed:()=>'',renderTaskAttachments:()=>'',renderChat:()=>'',composerHTML:()=>'',bindComposer(){},COLS:[{id:'todo',label:'To Do'}],avatar:()=>'',
     logActivity:()=>{throw Error('Unexpected task activity full-state save');},Notify:{both:()=>{throw Error('Unexpected notification write');}}});
   vm.runInContext(helpers,ctx);
   return {ctx,calls,toasts,state,elements,element,store};
 }
 test(`${file}: standalone task handlers contain no legacy task saves or duplicate effects`,()=>{
   for(const [start,end] of [['function openTeamTaskForm','function openTask'],['function openTask','async function deleteTask'],['async function deleteTask','function viewTasks'],['async function completeTask','function openNewTask'],['function openNewTask','function openNewRecurring'],['function openNewRecurring','function bindView']]){
     const body=section(start,end);assert.ok(body.length>0);assert.doesNotMatch(body,/Store\.save\(|logActivity\(|Notify\.both\(/);
   }
 });
 test(`${file}: standalone create form waits for API success without mutating tasks`,async()=>{
   const {ctx,element,calls,state}=setup();vm.runInContext(section('function openNewTask','function openNewRecurring'),ctx);
   ctx.openNewTask();await element('#create-task').onclick();assert.equal(calls[0][0],'create');assert.equal(calls[0][1].id,undefined);assert.equal(state.tasks.length,1);
 });
 test(`${file}: team task form uses task creation and guards duplicate submission`,async()=>{
   const {ctx,element,calls}=setup();ctx.session.role='team';vm.runInContext(section('function openTeamTaskForm','function openTask'),ctx);
   ctx.openTeamTaskForm();await Promise.all([element('#save-team-task').onclick(),element('#save-team-task').onclick()]);assert.equal(calls.length,1);assert.equal(calls[0][0],'create');
 });
 test(`${file}: task modal submits edits without optimistic local mutation`,async()=>{
   const {ctx,element,calls,state}=setup();vm.runInContext(section('function openTask','async function deleteTask'),ctx);
   ctx.openTask('t_test');await element('[data-save-task]').onclick();assert.equal(calls[0][0],'edit');assert.equal(calls[0][2].status,'review');assert.equal(calls[0][2].progress,'75');assert.equal(state.tasks[0].title,'Original');
 });
 test(`${file}: recurring form uses task API and server scheduling`,async()=>{
   const {ctx,element,calls}=setup();vm.runInContext(section('function openNewRecurring','function bindView'),ctx);
   ctx.openNewRecurring();await element('#rc-save').onclick();assert.equal(calls[0][0],'create');assert.equal(calls[0][1].recurring,'weekly');assert.equal(calls[0][1].nextRecurrenceAt,undefined);
 });
 test(`${file}: complete and delete never save history through state`,async()=>{
   const {ctx,calls,state}=setup();vm.runInContext(section('async function completeTask','function openNewTask')+section('async function deleteTask','function viewTasks'),ctx);
   await ctx.completeTask('t_test');await ctx.deleteTask('t_test');assert.deepEqual(JSON.parse(JSON.stringify(calls)),[['edit','t_test',{status:'done',progress:'completed'}],['delete','t_test']]);assert.equal(state.tasks[0].status,'todo');
 });
 test(`${file}: brief without voice uses POST and keeps server side effects on the server`,async()=>{
   const {ctx,calls}=setup();vm.runInContext(section('async function submitRequest','function openTeamTaskForm'),ctx);
   await ctx.submitRequest();assert.equal(calls.length,1);assert.equal(calls[0][0],'create');assert.equal(calls[0][1].title,'Brief');assert.equal(calls[0][1].dept,undefined);
 });
 test(`${file}: failed modal request preserves task and displays server error`,async()=>{
   const {ctx,element,toasts,state,store}=setup();store.saveTaskChanges=async()=>{throw Error('Invalid assignee');};vm.runInContext(section('function openTask','async function deleteTask'),ctx);
   ctx.openTask('t_test');await element('[data-save-task]').onclick();assert.equal(state.tasks[0].title,'Original');assert.ok(toasts.includes('Invalid assignee'));
 });
 test(file+': client profile form submits project linking and login in one action',async()=>{
   const {ctx,element,calls,state}=setup();
   for(const [field,value] of Object.entries({name:'Client',company:'Company',email:'client@example.test',phone:'123',password:'a-test-password',color:'#123456',project:'p1'}))element('#client-'+field).value=value;
   vm.runInContext(section('function openClientForm','async function deleteClient'),ctx);
   ctx.openClientForm();await element('#save-client').onclick();
   assert.equal(calls.length,1);assert.equal(calls[0][0],'saveClient');assert.equal(calls[0][2].project_id,'p1');
   assert.equal(calls[0][2].password,'a-test-password');assert.equal(state.clients.length,0);
 });
 test(file+': department form uses transactional action without local renames',async()=>{
   const {ctx,element,calls,state}=setup();element('#dept-name').value='QA';element('#dept-color').value='#123456';
   vm.runInContext(section('function openDepartmentForm','async function deleteDepartment'),ctx);
   ctx.openDepartmentForm();await element('#dept-save').onclick();
   assert.equal(calls.length,1);assert.equal(calls[0][0],'saveDepartment');assert.equal(calls[0][2].name,'QA');assert.equal(state.tasks[0].dept,'General');
 });
 test(file+': compound delete handlers wait for server and preserve local tasks',async()=>{
   const {ctx,calls,state}=setup();ctx.projectById=()=>({id:'p1',name:'Project'});ctx.clientById=()=>({id:'c1',company:'Client'});ctx.departments=()=>[{id:'d1',name:'Empty'}];
   vm.runInContext(section('async function deleteProject','/* ---------- ANDON')+section('async function deleteClient','/* ---------- INBOX')+section('async function deleteTeamMember','/* ---------- SETTINGS')+section('async function deleteDepartment','function viewTeam'),ctx);
   await ctx.deleteProject('p1');await ctx.deleteClient('c1');await ctx.deleteTeamMember('one');await ctx.deleteDepartment('d1');
   assert.deepEqual(calls.map(call=>call[0]),['deleteProject','deleteClient','deleteMember','deleteDepartment']);assert.equal(state.tasks[0].ownerId,'one');assert.equal(state.users.length,1);
 });
 test(file+': project grid uses one compound action without local mutation',async()=>{
   const {ctx,element,calls,state}=setup();element('#project-name').value='Grid';
   vm.runInContext(section('function openProjectForm','async function deleteProject'),ctx);
   ctx.openProjectForm();await element('#save-project').onclick();
   assert.equal(calls.length,1);assert.equal(calls[0][0],'project');assert.equal(calls[0][2].tasks.length,1);
   assert.equal(calls[0][2].tasks[0].id,undefined);assert.equal(state.tasks.length,1);assert.equal(state.projects.length,0);
 });
 test(file+': voice brief uses one compound action and retains draft on failure',async()=>{
   const {ctx,calls,store,toasts}=setup();ctx.pendingVoice='data:audio/webm;base64,YWJj';
   vm.runInContext(section('async function submitRequest','function openTeamTaskForm'),ctx);
   const create=store.createBrief;store.createBrief=async()=>{throw Error('Could not save voice');};
   await ctx.submitRequest();assert.equal(ctx.pendingVoice,'data:audio/webm;base64,YWJj');assert.ok(toasts.includes('Could not save voice'));
   store.createBrief=create;await ctx.submitRequest();assert.equal(calls.length,1);assert.equal(calls[0][0],'brief');assert.equal(ctx.pendingVoice,null);
 });
 test(file+': every migrated compound handler excludes full-state saves and local task mutation',()=>{
   for(const [start,end] of [['function openProjectForm','/* ---------- ANDON'],['function openClientForm','/* ---------- INBOX'],['function openDepartmentForm','function viewTeam'],['async function deleteTeamMember','/* ----------'],['async function submitRequest','function openTeamTaskForm']]){
     const body=section(start,end);assert.ok(body.length>0,start);assert.doesNotMatch(body,/Store\.save\(|logActivity\(|Notify\.both\(|S\(\)\.tasks\.push\(|S\(\)\.tasks\s*=/);
   }
 });

}
