const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');

  test(`${file}: Projects view includes a name search when projects exist`,()=>{
    const state={projects:[{id:'1',name:'Alpha & Co',status:'active'}],tasks:[]};
    const ctx=vm.createContext({S:()=>state,projectsByName:projects=>projects,clientById:()=>null,projectsPage:1,projectsSearch:''});
    vm.runInContext(code.slice(code.indexOf('const esc ='),code.indexOf('const userById')),ctx);
    vm.runInContext(code.slice(code.indexOf('function viewProjects(){'),code.indexOf('// Project grids')),ctx);
    const html=ctx.viewProjects();
    assert.match(html,/id="project-search"/);
    assert.match(html,/placeholder="Search projects by name…"/);
    assert.match(html,/data-project-name="Alpha &amp; Co"/);
    assert.match(html,/data-project-search-empty/);
  });

  test(`${file}: Projects paginates 18 cards and searches across every page`,()=>{
    const state={projects:Array.from({length:20},(_,i)=>({id:String(i+1),name:'Project '+String(i+1).padStart(2,'0'),status:'active'})),tasks:[]};
    const ctx=vm.createContext({S:()=>state,projectsByName:projects=>projects,clientById:()=>null,projectsPage:1,projectsSearch:''});
    vm.runInContext(code.slice(code.indexOf('const esc ='),code.indexOf('const userById')),ctx);
    vm.runInContext(code.slice(code.indexOf('function viewProjects(){'),code.indexOf('// Project grids')),ctx);
    let html=ctx.viewProjects();
    assert.equal((html.match(/data-project-card/g)||[]).length,18);
    assert.match(html,/Page 1 of 2 · 20 projects/);
    ctx.projectsPage=2;html=ctx.viewProjects();
    assert.equal((html.match(/data-project-card/g)||[]).length,2);
    assert.match(html,/Page 2 of 2 · 20 projects/);
    ctx.projectsSearch='Project 20';html=ctx.viewProjects();
    assert.match(html,/data-project-name="Project 20"/);
    assert.doesNotMatch(html,/data-project-name="Project 01"/);
    assert.equal(ctx.projectsPage,1);
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
