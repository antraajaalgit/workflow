const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');

  test(`${file}: project task rows include recurring controls and preserve the schedule`,()=>{
    const start=code.indexOf('function projectTaskRow');
    const end=code.indexOf('function renderProjectTaskFiles',start);
    const ctx=vm.createContext({assignableStaff:()=>[],departments:()=>[],esc:value=>String(value??''),taskProgressOptions:()=>'',projectDueTime:()=>Infinity});
    vm.runInContext(code.slice(start,end),ctx);
    const fresh=ctx.projectTaskRow(0);
    assert.match(fresh,/data-pt-recurring/);
    assert.match(fresh,/data-pt-frequency/);
    assert.match(fresh,/>Weekly<\/option>/);
    assert.doesNotMatch(fresh,/data-pt-recurring checked/);
    const existing=ctx.projectTaskRow(0,{id:'t1',recurring:'monthly'});
    assert.match(existing,/data-pt-recurring checked/);
    assert.match(existing,/value="monthly" selected>Monthly/);
  });

  test(`${file}: project save payload includes recurrence`,()=>{
    const form=code.slice(code.indexOf('function openProjectForm'),code.indexOf('async function deleteProject'));
    assert.match(form,/recurring:\$\('\[data-pt-recurring\]'\s*,row\)\.checked\?\$\('\[data-pt-frequency\]'\s*,row\)\.value:null/);
  });
}
