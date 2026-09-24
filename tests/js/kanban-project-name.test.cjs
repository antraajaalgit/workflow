const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');
  const kcardCode=code.slice(code.indexOf('function kcard(t){'),code.indexOf('function viewKanban(){'));

  test(`${file}: Kanban cards show their escaped project name`,()=>{
    const state={projects:[{id:'p1',name:'Alpha & <Launch>'}]};
    const ctx=vm.createContext({
      S:()=>state,projectById:id=>state.projects.find(project=>project.id===id),
      userById:()=>null,taskOwners:()=>[],departmentColor:()=>({bg:'#fff',fg:'#000'}),
      andonLevel:()=>'green',ACTIVE:['new','todo','in_progress','review'],fmtElapsed:()=>'',
      esc:value=>String(value??'').replace(/[&<>"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[char]))
    });
    vm.runInContext(kcardCode,ctx);
    const html=ctx.kcard({id:'t1',projectId:'p1',title:'Task',dept:'Design',status:'todo',priority:'med'});
    assert.match(html,/class="kc-project"/);
    assert.match(html,/Alpha &amp; &lt;Launch&gt;/);
  });

  test(`${file}: standalone Kanban cards have a clear fallback`,()=>{
    const ctx=vm.createContext({
      projectById:()=>undefined,userById:()=>null,taskOwners:()=>[],departmentColor:()=>({bg:'#fff',fg:'#000'}),
      andonLevel:()=>'green',ACTIVE:['new','todo','in_progress','review'],fmtElapsed:()=>'',esc:value=>String(value??'')
    });
    vm.runInContext(kcardCode,ctx);
    const html=ctx.kcard({id:'t2',title:'Standalone',dept:'General',status:'review',priority:'low'});
    assert.match(html,/Standalone task/);
    assert.match(html,/title="No project"/);
  });
}
