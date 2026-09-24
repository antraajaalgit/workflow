const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');

  test(`${file}: Tasks sorts incomplete projects first and completed tasks last`,()=>{
    const state={projects:[{id:'a',name:'Alpha'},{id:'b',name:'Beta'}]};
    const ctx=vm.createContext({S:()=>state});
    vm.runInContext(code.slice(code.indexOf('const projectById'),code.indexOf('const departments')),ctx);
    const tasks=[
      {id:'alpha-done',projectId:'a',title:'Completed by status',status:'done'},
      {id:'beta-open',projectId:'b',title:'Open',status:'todo',progress:'50'},
      {id:'alpha-open',projectId:'a',title:'Open',status:'todo',progress:'25'},
      {id:'beta-progress-done',projectId:'b',title:'Completed by progress',status:'review',progress:'completed'}
    ];
    const original=JSON.stringify(tasks);ctx.tasks=tasks;
    assert.deepEqual(
      Array.from(vm.runInContext('tasksByProjectNameWithCompletedLast(tasks)',ctx),task=>task.id),
      ['alpha-open','beta-open','alpha-done','beta-progress-done']
    );
    assert.equal(JSON.stringify(tasks),original);
  });
}
