const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');
  const now=new Date(2026,9,9,12).getTime();
  class Clock extends Date {static now(){return now;}}

  test(`${file}: History is available to admin and team dashboards`,()=>{
    const nav=code.slice(code.indexOf('const NAV ='),code.indexOf('function buildNav'));
    assert.equal((nav.match(/\{id:'history', ic:'🕘', label:'History'\}/g)||[]).length,2);
    assert.match(code.slice(code.indexOf('const TITLES'),code.indexOf('function render()')),/history:'History'/);
    assert.match(code.slice(code.indexOf('function render()'),code.indexOf('\/\* ---------- DASHBOARD')),/history: \(\) => \['admin','team'\]\.includes\(session\.role\)\?viewHistory\(\)/);
  });

  function setup(role='admin'){
    const day=24*60*60*1000;
    const state={
      clients:[{id:'client',company:'Client'}],
      projects:[{id:'project',name:'Project kept',status:'active'}],
      users:[{id:'team',name:'Team member'},{id:'other',name:'Other member'}],
      tasks:[
        {id:'active',projectId:'project',clientId:'client',ownerId:'team',title:'Active task',status:'todo',progress:'50',stageAt:now-day},
        {id:'recent-mine',projectId:'project',clientId:'client',ownerId:'team',title:'Recent mine',status:'done',progress:'completed',stageAt:now-5*day},
        {id:'recent-other',projectId:'project',clientId:'client',ownerId:'other',title:'Recent other',status:'done',progress:'completed',stageAt:now-10*day},
        {id:'boundary',projectId:'project',clientId:'client',ownerId:'team',title:'Thirty day boundary',status:'done',progress:'completed',stageAt:now-30*day},
        {id:'expired',projectId:null,clientId:null,ownerId:'team',title:'Expired standalone',status:'done',progress:'completed',stageAt:now-31*day},
        {id:'not-fully-complete',projectId:null,clientId:null,ownerId:'team',title:'Needs progress update',status:'done',progress:'75',stageAt:now-2*day},
        {id:'recurring-template',projectId:'project',clientId:'client',ownerId:'team',title:'Recurring template',status:'done',progress:'completed',stageAt:now-2*day,recurring:'weekly',nextRecurrenceAt:now+day},
      ],
    };
    const ownerIds=task=>task.ownerIds?.length?task.ownerIds:(task.ownerId?[task.ownerId]:[]);
    const ctx=vm.createContext({Date:Clock,S:()=>state,session:{id:'team',role},
      clientById:id=>state.clients.find(client=>client.id===id),projectById:id=>state.projects.find(project=>project.id===id),
      userById:id=>state.users.find(user=>user.id===id),isTaskOwner:(task,id)=>ownerIds(task).includes(id),
      projectsByName:projects=>projects,tasksByProjectNameWithCompletedLast:tasks=>tasks,
      tasksPage:1,tasksProjectFilter:'all',taskOwners:task=>ownerIds(task).map(id=>state.users.find(user=>user.id===id)).filter(Boolean),
      taskProgressLabel:value=>value,avatar:()=>'',dueCountdown:()=>''});
    vm.runInContext(code.slice(code.indexOf('const esc ='),code.indexOf('const userById')),ctx);
    vm.runInContext(code.slice(code.indexOf('const TASK_HISTORY_MS'),code.indexOf('const compareNames')),ctx);
    vm.runInContext(code.slice(code.indexOf('function viewHistory(){'),code.indexOf('function filterProjectCards')),ctx);
    vm.runInContext(code.slice(code.indexOf('function viewTasks(){'),code.indexOf('async function completeTask')),ctx);
    return {ctx};
  }

  test(`${file}: admin History shows completed tasks for less than 30 days`,()=>{
    const html=setup('admin').ctx.viewHistory();
    assert.match(html,/Recent mine/);assert.match(html,/Recent other/);assert.match(html,/Project kept/);
    assert.doesNotMatch(html,/Active task|Thirty day boundary|Expired standalone|Needs progress update|Recurring template/);
    assert.match(html,/Completed tasks remain here for 30 days/);
    assert.match(html,/Permanently deleted after/);
  });

  test(`${file}: team History contains only that member's recently completed tasks`,()=>{
    const html=setup('team').ctx.viewHistory();
    assert.match(html,/Recent mine/);
    assert.doesNotMatch(html,/Recent other|Thirty day boundary|Expired standalone|Active task/);
  });

  test(`${file}: active Tasks excludes fully completed tasks but keeps projects`,()=>{
    const html=setup('admin').ctx.viewTasks();
    assert.match(html,/data-task="active"/);assert.match(html,/data-task="not-fully-complete"/);
    assert.match(html,/data-complete-task="not-fully-complete"/);
    assert.doesNotMatch(html,/data-task="recent-mine"|data-task="recent-other"|data-task="boundary"|data-task="expired"/);
    assert.match(html,/>Project kept<\/option>/);
  });
}
