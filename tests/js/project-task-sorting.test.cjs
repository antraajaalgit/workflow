const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');
  const ctx=vm.createContext({
    $$:(_,list)=>[...list.rows],
    $:(selector,row)=>selector==='[data-pt-due]'?row.input:row.number,
  });
  vm.runInContext(code.slice(code.indexOf('function projectDueTime'),code.indexOf('function projectTaskRow')),ctx);
  const ids=tasks=>Array.from(tasks,t=>t.id);
  test(`${file}: project dates ascend, equal dates stay stable, state remains unchanged`,()=>{
    const tasks=[{id:'late',dueDate:300},{id:'none',dueDate:null},{id:'early',dueDate:100},{id:'middle',dueDate:200},{id:'tie',dueDate:100}];
    const original=[...tasks];
    assert.deepEqual(ids(ctx.projectTasksByDueDate(tasks)),['early','tie','middle','late','none']);
    assert.deepEqual(tasks,original);
    tasks[0].dueDate=50;
    assert.equal(ctx.projectTasksByDueDate(tasks)[0].id,'late');
    tasks.push({id:'added',dueDate:75});
    assert.deepEqual(ids(ctx.projectTasksByDueDate(tasks)),['late','added','early','tie','middle','none']);
  });
  test(`${file}: missing and invalid dates, empty and single lists are safe`,()=>{
    const tasks=[null,undefined,NaN,Infinity,'invalid','',-1,9e15].map((dueDate,id)=>({id,dueDate}));
    assert.deepEqual(ids(ctx.projectTasksByDueDate([...tasks,{id:'valid',dueDate:0}])),['valid',...tasks.map(t=>t.id)]);
    assert.equal(ctx.projectTasksByDueDate([]).length,0);
    assert.equal(ctx.projectTasksByDueDate([tasks[0]])[0],tasks[0]);
  });
  test(`${file}: live grid reorders existing rows after edits, additions and date removal`,()=>{
    const row=(id,due)=>({id,input:{valueAsNumber:due},number:{},_attachments:[id],draft:'Unsaved '+id});
    const list={rows:[row('late',300),row('early',100),row('none',NaN)],appendChild(row){this.rows.splice(this.rows.indexOf(row),1);this.rows.push(row);}};
    const late=list.rows[0];
    ctx.sortProjectTaskRows(list);
    assert.deepEqual(ids(list.rows),['early','late','none']);
    late.input.valueAsNumber=50;ctx.sortProjectTaskRows(list);
    assert.equal(list.rows[0],late);
    const added=row('added',75);list.rows.push(added);ctx.sortProjectTaskRows(list);
    assert.deepEqual(ids(list.rows),['late','added','early','none']);
    const none=list.rows[3];none.input.valueAsNumber=60;ctx.sortProjectTaskRows(list);
    assert.deepEqual(ids(list.rows),['late','none','added','early']);
    late.input.valueAsNumber=NaN;ctx.sortProjectTaskRows(list);
    assert.deepEqual(ids(list.rows),['none','added','early','late']);
    assert.equal(late.draft,'Unsaved late');assert.deepEqual(late._attachments,['late']);
    assert.deepEqual(list.rows.map(r=>r.number.textContent),[1,2,3,4]);
  });
  test(`${file}: project form wires sorting on opening, date changes and Add Task`,()=>{
    const form=code.slice(code.indexOf('function openProjectForm'),code.indexOf('async function deleteProject'));
    assert.match(form,/const projectTasks=projectTasksByDueDate\(editing\?S\(\)\.tasks\.filter/);
    assert.match(form,/list\.onchange=.*matches\('\[data-pt-due\]'\).*sortProjectTaskRows\(list\)/);
    assert.match(form,/'#add-project-task'.*onclick=.*sortProjectTaskRows\(list\)/);
  });
}
