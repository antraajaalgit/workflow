const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const clone=value=>JSON.parse(JSON.stringify(value));

for(const file of ['assets/js/store.js','public/assets/js/store.js']){
 function setup(){
  const requests=[],state={_revision:'r1',tasks:[{id:'t1',title:'Before'}],projects:[],clients:[],users:[],departments:[],messages:[],activity:[],notifications:[],settings:{amberMin:15}};
  let responseState={...clone(state),_revision:'r2',tasks:[{id:'t1',title:'Server task'}],projects:[{id:'p1',name:'Server project'}]},failure,hold;
  const ctx=vm.createContext({document:{querySelector:()=>({content:'csrf'})},fetch:async(url,options)=>{
    requests.push({url,...options,body:JSON.parse(options.body)});
    if(hold)await hold;
    return {ok:!failure,status:failure||200,json:async()=>failure?{message:'Rejected',errors:failure===422?{title:['Invalid title']}:undefined}:{state:clone(responseState),assignmentMailFailures:[]}};
  }});
  vm.runInContext(fs.readFileSync(file,'utf8')+'\nglobalThis.store=Store;',ctx);
  ctx.store.data=clone(state);ctx.store._baseline=clone(state);
  return {store:ctx.store,requests,fail:status=>failure=status,remote:value=>responseState=value,hold:promise=>hold=promise};
 }
 for(const [name,url,method,call] of [
  ['create project grid','/api/projects','POST',s=>s.saveProject(null,{name:'Grid',tasks:[{title:'Task'}],delete_ids:[]})],
  ['edit project grid','/api/projects/p1/tasks','PATCH',s=>s.saveProject('p1',{name:'Grid',tasks:[{id:'t1',title:'Task'}],delete_ids:[]})],
  ['delete project','/api/projects/p1','DELETE',s=>s.deleteProject('p1')],
  ['create client and link project','/api/clients','POST',s=>s.saveClient(null,{name:'Client',project_id:'p1',password:'test password'})],
  ['edit client and link project','/api/clients/c1','PATCH',s=>s.saveClient('c1',{name:'Client',project_id:'p1'})],
  ['delete client','/api/clients/c1','DELETE',s=>s.deleteClient('c1')],
  ['remove member','/api/team-members/u1','DELETE',s=>s.deleteMember('u1')],
  ['create department','/api/departments','POST',s=>s.saveDepartment(null,{name:'Design'})],
  ['rename department','/api/departments/d1','PATCH',s=>s.saveDepartment('d1',{name:'Design'})],
  ['delete department','/api/departments/d1','DELETE',s=>s.deleteDepartment('d1')],
  ['voice brief','/api/briefs','POST',s=>s.createBrief({title:'Voice',desc:'Details',clientId:'c1'},'data:audio/webm;base64,YWJj')],
 ])test(`${file}: ${name} makes one request and adopts authoritative state`,async()=>{
   const {store,requests}=setup();await call(store);
   assert.equal(requests.length,1);assert.equal(requests[0].url,url);assert.equal(requests[0].method,method);
   assert.equal(requests[0].body._revision,'r1');assert.equal(requests[0].credentials,'same-origin');assert.equal(requests[0].headers['X-CSRF-TOKEN'],'csrf');
   assert.equal(store.data.tasks[0].title,'Server task');assert.equal(store.data.projects[0].name,'Server project');assert.equal(store._baseline._revision,'r2');
   if(name==='voice brief')assert.deepEqual(requests[0].body.task,{title:'Voice',description:'Details',client_id:'c1'});
 });
 for(const status of [403,404,409,422,500])test(`${file}: failed compound ${status} preserves local records`,async()=>{
   const {store,requests,fail}=setup();const before=clone(store.data);fail(status);
   await assert.rejects(store.deleteProject('p1'),status===422?/Invalid title/:/Rejected/);
   assert.deepEqual(clone(store.data.tasks),before.tasks);assert.deepEqual(clone(store.data.projects),before.projects);assert.equal(requests.length,1);
 });
 test(`${file}: compound success blocks an already queued old full-state save`,async()=>{
   const {store,requests,hold}=setup();let release;hold(new Promise(resolve=>release=resolve));
   const action=store.deleteProject('p1'),save=store.save();const rejected=assert.rejects(save,/Reload/);
   release();await action;await rejected;assert.equal(requests.length,1);
 });
 test(`${file}: compound sync preserves unrelated drafts and detects concurrent changes`,async()=>{
   const {store}=setup();store.data.settings.amberMin=25;await store.saveDepartment('d1',{name:'Design'});
   assert.equal(store.data.settings.amberMin,25);assert.equal(store.data._revision,'r2');
   const second=setup();second.store.data.projects=[{id:'local',name:'Unsaved'}];
   const result=await second.store.saveProject('p1',{name:'Server'});assert.ok(result.syncWarning);assert.equal(second.store.data._revision,null);
 });
 test(`${file}: modal revision is not silently upgraded before submitting a compound action`,async()=>{
   const {store,requests}=setup();await store.saveProject('p1',{name:'Project',_revision:'form-open-revision'});
   assert.equal(requests[0].body._revision,'form-open-revision');
 });
}
