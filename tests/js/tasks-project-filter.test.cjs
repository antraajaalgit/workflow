const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');
  function setup(){
    const state={projects:[{id:'z',name:'Zulu'},{id:'a',name:'Alpha & Co'},{id:'empty',name:'Empty'}],tasks:[]};
    for(let i=0;i<14;i++) state.tasks.push({id:'a'+i,title:'Task '+String(i).padStart(2,'0'),projectId:'a',ownerId:i===13?'other':'team',status:'todo'});
    state.tasks.push({id:'z1',title:'Zulu task',projectId:'z',ownerId:'team',status:'done'},
      {id:'s1',title:'Standalone',ownerId:'team'}, {id:'s2',title:'Standalone null',projectId:null,ownerId:'other'},
      {id:'shared',title:'Shared',projectId:'z',ownerId:'other',ownerIds:['other','team']});
    const select={value:'all'};let renders=0;
    const ctx=vm.createContext({S:()=>state,session:{id:'admin',role:'admin'},tasksPage:1,tasksProjectFilter:'all',
      clientById:()=>null,projectById:id=>state.projects.find(p=>p.id===id),userById:()=>null,taskOwners:()=>[],
      isTaskOwner:(t,id)=>(t.ownerIds||[t.ownerId]).includes(id),taskProgressLabel:()=>'',avatar:()=>'',
      $:()=>select,render:()=>renders++});
    vm.runInContext(code.slice(code.indexOf('const esc ='),code.indexOf('const userById')),ctx);
    vm.runInContext(code.slice(code.indexOf('const compareNames'),code.indexOf('const departments')),ctx);
    vm.runInContext(code.slice(code.indexOf('function viewTasks(){'),code.indexOf('async function completeTask')),ctx);
    const binding=code.split('\n').find(line=>line.includes("const projectFilter=$('#tasks-project-filter')"));
    vm.runInContext(binding,ctx);
    return {ctx,state,change(value){select.value=value;select.onchange();},renders:()=>renders};
  }
  const ids=html=>[...html.matchAll(/<article[^>]*data-task="([^"]+)"/g)].map(m=>m[1]);
  test(`${file}: dropdown defaults to all, lists sorted projects and paginates filtered results`,()=>{
    const {ctx,state,change,renders}=setup();
    const original=JSON.stringify(state);
    let html=ctx.viewTasks();
    assert.match(html,/<label for="tasks-project-filter">Project<\/label>/);
    assert.match(html,/<option value="all" selected>All Projects/);
    assert.ok(html.indexOf('Alpha &amp; Co</option>')<html.indexOf('Zulu</option>'));
    assert.match(html,/>Standalone Tasks<\/option>/);
    assert.equal(ids(html).length,12);
    ctx.tasksPage=2;change('project:a');assert.equal(ctx.tasksPage,1);assert.equal(renders(),1);
    html=ctx.viewTasks();assert.deepEqual(ids(html),Array.from({length:12},(_,i)=>'a'+i));
    assert.match(html,/Page 1 of 2 · 14 tasks/);
    assert.match(html,/<option value="project:a" selected>/);
    ctx.tasksPage=2;assert.deepEqual(ids(ctx.viewTasks()),['a12','a13']);
    change('standalone');assert.equal(ctx.tasksPage,1);assert.deepEqual(ids(ctx.viewTasks()),['s1','s2']);
    change('project:empty');assert.deepEqual(ids(ctx.viewTasks()),[]);assert.match(ctx.viewTasks(),/No tasks yet/);
    change('all');assert.equal(ids(ctx.viewTasks()).length,12);
    assert.equal(JSON.stringify(state),original);
  });
  test(`${file}: team visibility and card actions survive project filtering`,()=>{
    const {ctx,change}=setup();ctx.session={id:'team',role:'team'};
    change('project:a');ctx.tasksPage=2;assert.deepEqual(ids(ctx.viewTasks()),['a12']);
    change('standalone');assert.deepEqual(ids(ctx.viewTasks()),['s1']);
    change('project:z');const html=ctx.viewTasks();
    assert.deepEqual(ids(html),file.startsWith('public')?['shared','z1']:['z1']);
    assert.match(html,/data-edit-task="z1"/);assert.match(html,/data-delete-task="z1"/);
    assert.match(html,/task-row-completed/);assert.doesNotMatch(html,/data-complete-task="z1"/);
    change('project:a');assert.match(ctx.viewTasks(),/data-complete-task="a0"/);
  });
}

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');
  test(file+': pagination binding tolerates absent controls and binds all page buttons',()=>{
    const binding=code.split('\n').find(line=>line.includes("[data-tasks-page]').forEach"));
    const buttons=[];let renders=0;
    const ctx=vm.createContext({$:()=>null,$$:()=>buttons,tasksPage:1,render:()=>renders++});
    assert.doesNotThrow(()=>vm.runInContext(binding,ctx));
    buttons.push({disabled:true,dataset:{tasksPage:'0'}},{disabled:false,dataset:{tasksPage:'2'}});
    vm.runInContext(binding,ctx);
    buttons[0].onclick();assert.equal(ctx.tasksPage,1);assert.equal(renders,0);
    buttons[1].onclick();assert.equal(ctx.tasksPage,2);assert.equal(renders,1);
  });
}
