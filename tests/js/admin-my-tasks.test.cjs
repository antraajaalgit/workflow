const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');

  test(`${file}: admin navigation and router expose My Tasks`,()=>{
    const nav=code.slice(code.indexOf('const NAV ='),code.indexOf('function buildNav'));
    assert.match(nav,/\{id:'my-tasks', ic:'👤', label:'My Tasks'\}/);
    assert.match(code.slice(code.indexOf('const TITLES'),code.indexOf('function render()')),/'my-tasks':'My Tasks'/);
    assert.match(code.slice(code.indexOf('function render()'),code.indexOf('\/\* ---------- DASHBOARD')),/'my-tasks': \(\) => session\.role==='admin'\?viewTasks\(\)/);
  });

  test(`${file}: admin My Tasks filters to the signed-in admin and keeps admin creation`,()=>{
    const state={projects:[{id:'p',name:'Project'}],tasks:[
      {id:'primary',projectId:'p',ownerId:'admin',title:'Primary assignee',status:'todo',progress:'25',priority:'med'},
      {id:'secondary',projectId:'p',ownerId:'other',ownerIds:['other','admin'],title:'Secondary assignee',status:'todo',progress:'25',priority:'med'},
      {id:'other',projectId:'p',ownerId:'other',ownerIds:['other'],title:'Other user only',status:'todo',progress:'25',priority:'med'}
    ]};
    const ctx=vm.createContext({S:()=>state,session:{id:'admin',role:'admin'},route:'my-tasks',tasksPage:1,tasksProjectFilter:'all',
      clientById:()=>null,projectById:id=>state.projects.find(project=>project.id===id),userById:()=>null,taskOwners:()=>[],isTaskOwner:(task,id)=>(task.ownerIds||[task.ownerId]).includes(id),
      taskProgressLabel:()=>'',avatar:()=>'',Date});
    vm.runInContext(code.slice(code.indexOf('const esc ='),code.indexOf('const userById')),ctx);
    vm.runInContext(code.slice(code.indexOf('const compareNames'),code.indexOf('const departments')),ctx);
    vm.runInContext(code.slice(code.indexOf('function viewTasks(){'),code.indexOf('async function completeTask')),ctx);
    const html=ctx.viewTasks();
    assert.match(html,/data-task="primary"/);
    assert.match(html,/data-task="secondary"/);
    assert.doesNotMatch(html,/data-task="other"/);
    assert.match(html,/data-new-task/);
    assert.match(html,/View, manage, and complete tasks assigned to you/);
  });
}
