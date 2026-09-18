const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');
  function setup(){
    const now=new Date(2026,8,16,15).getTime();
    const state={projects:[],tasks:[],users:[{id:'a',name:'Alice',role:'team'},{id:'b',name:'Bob',role:'team'}]};
    let modal;
    class Clock extends Date {static now(){return now;}}
    const ctx=vm.createContext({Date:Clock,S:()=>state,team:state.users,avatar:()=>'',
      esc:s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'),
      isTaskOwner:(t,id)=>(t.ownerIds?.length?t.ownerIds:[t.ownerId]).includes(id),
      TASK_PROGRESS_OPTIONS:[['just_started','Just started'],['completed','Completed']],
      userById:id=>state.users.find(u=>u.id===id),projectById:id=>state.projects.find(p=>p.id===id),taskProgressLabel:()=>'To do',
      document:{createElement:()=>({querySelectorAll:()=>[],remove(){}})},$:()=>({appendChild:m=>{modal=m;}}),
    });
    vm.runInContext(code.slice(code.indexOf('function projectDueTime'),code.indexOf('function projectTasksByDueDate')),ctx);
    vm.runInContext(code.slice(code.indexOf('function memberTasksCrossedDeadline'),code.indexOf('function viewDashboard')),ctx);
    const rows=code.slice(code.indexOf('  const progressRows = team.map'),code.indexOf('  const ownProgressSummary'));
    vm.runInContext('function renderRows(){'+rows+'return progressRows;}',ctx);
    return {ctx,state,now,getModal:()=>modal};
  }
  test(`${file}: counts overdue incomplete tasks regardless of project due date`,()=>{
    const {ctx,state,now}=setup();
    const yesterday=new Date(2026,8,15,12).getTime();
    const earlierToday=new Date(2026,8,16,9).getTime();
    state.projects=[{id:'p',name:'Project',dueDate:now+86400000,status:'active'}];
    state.tasks=[
      {id:'one',projectId:'p',ownerId:'a',title:'First',dueDate:yesterday,status:'todo'},
      {id:'two',projectId:'p',ownerId:'a',title:'Second',dueDate:yesterday,status:'review'},
      {id:'standalone',ownerId:'a',title:'Standalone',dueDate:yesterday,status:'todo'},
      {id:'today',ownerId:'a',dueDate:earlierToday,status:'todo'},
      {id:'future',ownerId:'a',dueDate:now+1,status:'todo'},
      {id:'done',ownerId:'a',dueDate:yesterday,status:'done'},
      {id:'complete',ownerId:'a',dueDate:yesterday,status:'todo',progress:'completed'},
      ...[null,undefined,'invalid',NaN,Infinity,-1,9e15].map((dueDate,i)=>({id:'invalid'+i,ownerId:'a',dueDate,status:'todo'})),
    ];
    assert.deepEqual(Array.from(ctx.memberTasksCrossedDeadline(state.tasks,now),t=>t.id),['one','two','standalone']);
    const html=ctx.renderRows();
    assert.match(html,/Tasks Crossed Deadline: <b>3<\/b>/);
    assert.match(html,/Tasks Crossed Deadline: <b>0<\/b>/);
  });
  test(`${file}: counts a multi-assignee task for the secondary assignee`,()=>{
    const {ctx,state}=setup();
    const yesterday=new Date(2026,8,15,12).getTime();
    state.tasks=[{id:'shared',ownerId:'a',ownerIds:['a','b'],title:'Shared',dueDate:yesterday,status:'todo'}];
    const html=ctx.renderRows();
    assert.match(html,/Alice[\s\S]*?Tasks Crossed Deadline: <b>1<\/b>/);
    assert.match(html,/Bob[\s\S]*?Tasks Crossed Deadline: <b>1<\/b>/);
  });
  test(`${file}: details show overdue task names safely`,()=>{
    const {ctx,state,getModal}=setup();
    state.tasks=[{id:'late',ownerId:'a',title:'Late <script> & Co',dueDate:new Date(2026,8,15,12).getTime(),status:'todo'}];
    let html;
    if(file.startsWith('public/')){
      vm.runInContext(code.slice(code.indexOf('function openMemberTaskDetails'),code.indexOf('function dashboardCalendar')),ctx);
      ctx.openMemberTaskDetails('a');html=getModal().innerHTML;
      assert.match(html,/Tasks Crossed Deadline \(1\)/);
    }else html=ctx.renderRows();
    assert.match(html,/Late &lt;script&gt; &amp; Co/);
    assert.doesNotMatch(html,/<script>/);
    assert.match(ctx.crossedDeadlineTaskRows([]),/No tasks crossed deadline/);
  });
}
