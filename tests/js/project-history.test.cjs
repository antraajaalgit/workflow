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
      clients:[],
      projects:[
        {id:'active',name:'Active project',status:'active'},
        {id:'recent-mine',name:'Recent mine',status:'completed',completedAt:now-5*day},
        {id:'recent-other',name:'Recent other',status:'completed',completedAt:now-10*day},
        {id:'boundary',name:'Thirty day boundary',status:'completed',completedAt:now-30*day},
        {id:'expired',name:'Expired project',status:'completed',completedAt:now-31*day},
      ],
      tasks:[
        {id:'active-task',projectId:'active',ownerId:'team',title:'Active task',status:'todo'},
        {id:'mine',projectId:'recent-mine',ownerId:'team',title:'Mine',status:'done',progress:'completed'},
        {id:'other',projectId:'recent-other',ownerId:'other',title:'Other',status:'done',progress:'completed'},
        {id:'boundary-task',projectId:'boundary',ownerId:'team',title:'Boundary',status:'done',progress:'completed'},
        {id:'old',projectId:'expired',ownerId:'team',title:'Old',status:'done',progress:'completed'},
        {id:'standalone',projectId:null,ownerId:'team',title:'Standalone',status:'todo'},
      ],
    };
    const ctx=vm.createContext({Date:Clock,S:()=>state,session:{id:'team',role},clientById:()=>null,
      isTaskOwner:(task,id)=>(task.ownerIds||[task.ownerId]).includes(id),projectsByName:projects=>projects,
      projectById:id=>state.projects.find(project=>project.id===id),userById:()=>null,tasksByProjectNameWithCompletedLast:tasks=>tasks,
      tasksPage:1,tasksProjectFilter:'all',taskOwners:()=>[],taskProgressLabel:()=>'',avatar:()=>''});
    vm.runInContext(code.slice(code.indexOf('const esc ='),code.indexOf('const userById')),ctx);
    vm.runInContext(code.slice(code.indexOf('const PROJECT_HISTORY_MS'),code.indexOf('const departments')),ctx);
    vm.runInContext(code.slice(code.indexOf('function viewHistory(){'),code.indexOf('function filterProjectCards')),ctx);
    vm.runInContext(code.slice(code.indexOf('function viewTasks(){'),code.indexOf('async function completeTask')),ctx);
    return {ctx,state};
  }

  test(`${file}: admin History keeps completed projects for 30 days and excludes active or expired projects`,()=>{
    const {ctx}=setup('admin');const html=ctx.viewHistory();
    assert.match(html,/Recent mine/);assert.match(html,/Recent other/);assert.match(html,/Thirty day boundary/);
    assert.doesNotMatch(html,/Active project/);assert.doesNotMatch(html,/Expired project/);
    assert.match(html,/Completed projects remain here for 30 days/);
  });

  test(`${file}: team History contains only recently completed projects assigned to that member`,()=>{
    const {ctx}=setup('team');const html=ctx.viewHistory();
    assert.match(html,/Recent mine/);assert.match(html,/Thirty day boundary/);
    assert.doesNotMatch(html,/Recent other|Expired project|Active project/);
  });

  test(`${file}: active Tasks excludes completed-project tasks and their project filter`,()=>{
    const {ctx}=setup('admin');const html=ctx.viewTasks();
    assert.match(html,/data-task="active-task"/);assert.match(html,/data-task="standalone"/);
    assert.doesNotMatch(html,/data-task="mine"|data-task="other"|data-task="boundary-task"|data-task="old"/);
    assert.match(html,/>Active project<\/option>/);
    assert.doesNotMatch(html,/>Recent mine<\/option>|>Expired project<\/option>/);
  });
}
