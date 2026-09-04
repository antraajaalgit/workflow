const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

for (const file of ['assets/js/store.js', 'public/assets/js/store.js']) {
  function setup(fetch) {
    const context = vm.createContext({fetch, document:{querySelector:()=>({content:'csrf'})}});
    vm.runInContext(fs.readFileSync(file,'utf8')+'\nglobalThis.store = Store;', context);
    context.store.data = {_revision:'r1', tasks:[{id:'task',title:'one'}],settings:{amberMin:15}};
    return context.store;
  }
  test(`${file}: serializes snapshots with the latest successful revision`, async () => {
    const requests = [];
    let release;
    const store = setup(async (url, options) => {
      const body = JSON.parse(options.body);
      requests.push(body);
      if (requests.length === 1) await new Promise(resolve => release=resolve);
      return {ok:true,status:200,json:async()=>({_revision:'r'+(requests.length+1),saved:true})};
    });
    const first = store.save();
    store.data.settings.amberMin = 20;
    const second = store.save();
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(requests.length,1);
    release();
    await Promise.all([first,second]);
    assert.equal(requests[0].tasks,undefined);assert.equal(requests[0].settings.amberMin,15);
    assert.equal(requests[1].tasks,undefined);assert.equal(requests[1].settings.amberMin,20);
    assert.equal(requests[1]._revision,'r2');
    assert.equal(store.data._revision,'r3');
  });
  test(`${file}: conflict blocks queued stale writes until reload`, async () => {
    let requests=0;
    const store=setup(async()=>{requests++;return {ok:false,status:409,json:async()=>({message:'Reload before saving'})};});
    const results=await Promise.allSettled([store.save(),store.save()]);
    assert.ok(results.every(result=>result.status==='rejected'));
    assert.equal(requests,1);
    assert.equal(store.data._revision,null);
  });
  test(`${file}: missing revision fails closed without a request`, async () => {
    const store=setup(()=>{throw new Error('Should not fetch');});
    delete store.data._revision;
    await assert.rejects(store.save(),/Reload/);
  });
}
