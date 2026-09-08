const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');
  function setup(storage=new Map()){
    const elements={};
    for(const id of ['#bell-count','#notif-drawer','#scrim','#notif-list']){
      const classes=new Set(['hidden']);
      elements[id]={classList:{contains:c=>classes.has(c),toggle(c,on){on?classes.add(c):classes.delete(c);}}};
    }
    const state={notifications:[{id:'first',text:'Hello',at:1}]};
    const ctx=vm.createContext({session:{id:'admin',role:'admin'},S:()=>state,$:s=>elements[s],
      localStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v)},esc:s=>s,timeAgo:()=>''});
    vm.runInContext(code.slice(code.indexOf('const notificationReads'),code.indexOf('function logActivity')),ctx);
    vm.runInContext(code.slice(code.indexOf('function toggleDrawer'),code.indexOf('/* ---------- boot')),ctx);
    return {ctx,state,elements};
  }
  test(`${file}: viewing clears badge, persists across reload, and keeps users independent`,()=>{
    const storage=new Map();
    const {ctx,state,elements}=setup(storage);
    ctx.updateBell();
    assert.equal(elements['#bell-count'].textContent,1);
    ctx.toggleDrawer(true);
    assert.equal(elements['#bell-count'].textContent,0);
    assert.equal(elements['#bell-count'].classList.contains('hidden'),true);
    assert.match(elements['#notif-list'].innerHTML,/Hello/);
    ctx.toggleDrawer(false);
    state.notifications.push({id:'second',text:'New',at:1});
    ctx.updateBell();
    assert.equal(elements['#bell-count'].textContent,1);
    const reloaded=setup(storage);
    reloaded.ctx.updateBell();
    assert.equal(reloaded.elements['#bell-count'].textContent,0);
    ctx.session={id:'team',role:'team'};
    ctx.updateBell();
    assert.equal(elements['#bell-count'].textContent,2);
    ctx.toggleDrawer(true);
    assert.equal(elements['#bell-count'].textContent,0);
    state.notifications.push({id:'third',text:'While open',at:2});
    ctx.updateBell();
    assert.match(elements['#notif-list'].innerHTML,/While open/);
    assert.equal(elements['#bell-count'].textContent,0);
    assert.equal(state.notifications.length,3);
  });
}
