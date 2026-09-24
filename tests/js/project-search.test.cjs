const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');

  test(`${file}: Projects view includes a name search when projects exist`,()=>{
    const state={projects:[{id:'1',name:'Alpha & Co',status:'active'}],tasks:[]};
    const ctx=vm.createContext({S:()=>state,projectsByName:projects=>projects,clientById:()=>null});
    vm.runInContext(code.slice(code.indexOf('const esc ='),code.indexOf('const userById')),ctx);
    vm.runInContext(code.slice(code.indexOf('function viewProjects(){'),code.indexOf('// Project grids')),ctx);
    const html=ctx.viewProjects();
    assert.match(html,/id="project-search"/);
    assert.match(html,/placeholder="Search projects by name…"/);
    assert.match(html,/data-project-name="Alpha &amp; Co"/);
    assert.match(html,/data-project-search-empty/);
  });

  test(`${file}: project search is trimmed, case-insensitive, and toggles empty state`,()=>{
    const functionCode=code.slice(code.indexOf('function filterProjectCards'),code.indexOf('// Project grids'));
    const makeNode=name=>({dataset:{projectName:name},hidden:false,classList:{toggle(_class,force){this.owner.hidden=force;}}});
    const cards=[makeNode('Alpha Launch'),makeNode('Beta Website')];
    cards.forEach(card=>card.classList.owner=card);
    const empty={hidden:true,classList:{toggle(_class,force){empty.hidden=force;}}};
    const ctx=vm.createContext({});vm.runInContext(functionCode,ctx);
    assert.equal(ctx.filterProjectCards('  ALPHA  ',cards,empty),1);
    assert.deepEqual(cards.map(card=>card.hidden),[false,true]);
    assert.equal(empty.hidden,true);
    assert.equal(ctx.filterProjectCards('missing',cards,empty),0);
    assert.equal(empty.hidden,false);
  });
}
