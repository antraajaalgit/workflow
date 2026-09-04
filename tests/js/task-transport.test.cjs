const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const clone=value=>JSON.parse(JSON.stringify(value));

for(const file of ['assets/js/store.js','public/assets/js/store.js']) {
  function setup() {
    let revision=1;
    const requests=[],meta={content:'csrf-one'};
    const task={id:'t_existing',client_id:'client',project_id:null,title:'Original',description:'Description',department:'General',owner_id:'one',owner_ids:['one'],status:'todo',priority:'med',progress:'just_started',created_at_ms:100,stage_at_ms:100,due_date_ms:null,recurring:null,next_recurrence_at_ms:null,attachments:[]};
    const server={tasks:[task],messages:[{id:'message',taskId:task.id}],settings:{amberMin:15},activity:[],notifications:[],users:[],clients:[],projects:[]};
    let fail=null,hold=null,ctx;
    const state=()=>({...clone(server),tasks:server.tasks.map(t=>clone(ctx.store.taskFromApi(t))),_revision:'r'+revision});
    const fetch=async(url,options={})=>{
      const method=options.method||'GET',body=options.body?JSON.parse(options.body):undefined;
      requests.push({url,method,body,headers:options.headers,credentials:options.credentials});
      if(hold){const wait=hold;hold=null;await wait;}
      if(fail && (!fail.url||fail.url===url) && (fail.method===undefined||fail.method===method)) {const error=fail;fail=null;return {ok:false,status:error.status,json:async()=>({message:'Denied',errors:error.errors})};}
      if(url==='/api/session')return {ok:true,status:200,json:async()=>({userId:'one',csrfToken:'csrf-two'})};
      if(url==='/api/state'){
        if(method==='PUT'){
          assert.equal(body._revision,'r'+revision);
          server.settings=clone(body.settings);revision++;
          return {ok:true,status:200,json:async()=>({_revision:'r'+revision,saved:true})};
        }
        return {ok:true,status:200,json:async()=>state()};
      }
      let result;
      if(method==='POST') {result={...clone(task),...body,id:'t_server_'+server.tasks.length};server.tasks.push(result);}
      else {
        const [,encoded,suffix]=url.match(/^\/api\/tasks\/([^/]+)(.*)$/);
        const id=decodeURIComponent(encoded),current=server.tasks.find(t=>t.id===id);
        if(method==='DELETE') {server.tasks=server.tasks.filter(t=>t.id!==id);server.messages.forEach(m=>{if(m.taskId===id)m.taskId=null;});result={deleted:true};}
        else {
          Object.assign(current,body);
          if(body.owner_ids){current.owner_id=body.owner_ids[0]||null;}
          if(body.status)current.stage_at_ms=500;
          result=clone(current);
        }
      }
      revision++;server.activity.push({id:'a'+revision,text:'Server history'});
      return {ok:true,status:method==='POST'?201:200,json:async()=>clone(result)};
    };
    ctx=vm.createContext({fetch,document:{querySelector:()=>meta}});
    vm.runInContext(fs.readFileSync(file,'utf8')+'\nglobalThis.store=Store;',ctx);
    ctx.store.data=state();ctx.store._baseline=clone(ctx.store.data);
    return {store:ctx.store,requests,server,meta,fail:error=>fail=error,hold:promise=>hold=promise};
  }
  const label=name=>`${file}: ${name}`;
  test(label('create sends only a task POST and applies the server ID and fields'),async()=>{
    const {store,requests}=setup();
    const result=await store.createTask({id:'ignored',title:'Created',desc:'Details',ownerIds:['one','two'],priority:'high',dueDate:12345,attachments:[],settings:{bad:true}});
    assert.equal(result.id,'t_server_1');
    assert.deepEqual(requests.map(r=>[r.method,r.url]),[['POST','/api/tasks'],['GET','/api/state']]);
    assert.deepEqual(requests[0].body,{title:'Created',description:'Details',owner_ids:['one','two'],priority:'high',due_date_ms:12345,attachments:[]});
    assert.equal(store.data.tasks.at(-1).desc,'Details');
    assert.equal(store.data.tasks.at(-1).createdAt,100);
    assert.equal(store.data.activity.length,1);
    assert.equal(store.data._revision,'r2');
  });
  for(const [name,method,suffix,payload,call,field,value] of [
    ['edit','PATCH','',{title:'Edited'},s=>s.updateTask('t_existing',{title:'Edited'}),'title','Edited'],
    ['status','PATCH','/status',{status:'review'},s=>s.setTaskStatus('t_existing','review'),'status','review'],
    ['progress','PATCH','/progress',{progress:'75'},s=>s.setTaskProgress('t_existing','75'),'progress','75'],
    ['assignees','PATCH','/assignees',{owner_ids:['two','one']},s=>s.setTaskAssignees('t_existing',['two','one']),'ownerId','two'],
  ]) test(label(`${name} uses its endpoint and authoritative response without PUT`),async()=>{
    const {store,requests}=setup();await call(store);
    assert.equal(requests[0].method,method);assert.equal(requests[0].url,'/api/tasks/t_existing'+suffix);
    assert.deepEqual(requests[0].body,payload);assert.equal(store.data.tasks[0][field],value);
    assert.equal(requests.filter(r=>r.method==='PUT').length,0);
    if(name==='assignees')assert.deepEqual(clone(store.data.tasks[0].ownerIds),['two','one']);
  });
  test(label('delete waits for success then removes the task and detaches messages'),async()=>{
    const {store,requests,hold}=setup();let release;hold(new Promise(r=>release=r));
    const deletion=store.deleteTask('t_existing');await new Promise(r=>setImmediate(r));
    assert.equal(store.data.tasks.length,1);release();await deletion;
    assert.equal(requests[0].method,'DELETE');assert.equal(requests[0].url,'/api/tasks/t_existing');
    assert.equal(store.data.tasks.length,0);assert.equal(store.data.messages[0].taskId,null);
  });
  for(const status of [403,404,409,422])test(label(`${status} preserves local task and surfaces validation errors`),async()=>{
    const {store,requests,fail}=setup(),before=clone(store.data.tasks);
    fail({status,errors:status===422?{title:['A task title is required.']}:undefined});
    await assert.rejects(store.updateTask('t_existing',{title:'Bad'}),status===422?/A task title is required/:/Denied/);
    assert.deepEqual(clone(store.data.tasks),before);assert.equal(requests.length,1);
  });
  test(label('failed create and delete cannot appear locally successful'),async()=>{
    const {store,fail}=setup();fail({status:422});await assert.rejects(store.createTask({title:''}));
    assert.equal(store.data.tasks.length,1);fail({status:403});await assert.rejects(store.deleteTask('t_existing'));
    assert.equal(store.data.tasks.length,1);assert.equal(store.data.messages[0].taskId,'t_existing');
  });
  test(label('modal changes use one atomic PATCH and send only changed fields'),async()=>{
    const {store,requests}=setup();
    await store.saveTaskChanges('t_existing',{title:'Edited',priority:'med',status:'review',progress:'50',ownerIds:['two']});
    assert.deepEqual(requests.filter(r=>r.method==='PATCH').map(r=>[r.url,r.body]),[
      ['/api/tasks/t_existing',{title:'Edited',status:'review',progress:'50',owner_ids:['two']}],
    ]);
    const count=requests.length;await store.saveTaskChanges('t_existing',{title:'Edited'});assert.equal(requests.length,count);
  });
  test(label('later non-task saves remain available with the refreshed revision'),async()=>{
    const {store,requests}=setup();await store.setTaskProgress('t_existing','25');
    store.data.settings.amberMin=20;await store.save();
    const put=requests.find(r=>r.method==='PUT');assert.equal(put.url,'/api/state');
    assert.equal(put.body.tasks,undefined);assert.equal(store.data.tasks[0].progress,'25');assert.equal(put.body._revision,'r2');
  });
  test(label('old queued state snapshots cannot overwrite a successful targeted task'),async()=>{
    const {store,requests}=setup();
    const outcomes=await Promise.allSettled([store.setTaskStatus('t_existing','review'),store.save()]);
    assert.equal(outcomes[0].status,'fulfilled');assert.equal(outcomes[1].status,'rejected');
    assert.equal(requests.filter(r=>r.method==='PUT').length,0);assert.equal(store.data.tasks[0].status,'review');
  });
  test(label('unrelated local edits survive refresh; conflicting remote edits fail closed'),async()=>{
    const {store,server}=setup();store.data.settings.amberMin=30;
    await store.setTaskProgress('t_existing','25');assert.equal(store.data.settings.amberMin,30);assert.equal(store.data._revision,'r2');
    server.settings.amberMin=40;
    const result=await store.setTaskProgress('t_existing','50');assert.match(result.syncWarning,/Other data changed/);
    assert.equal(store.data.settings.amberMin,30);assert.equal(store.data._revision,null);
  });
  test(label('refresh failure keeps committed response and never retries creation'),async()=>{
    const {store,requests,fail}=setup();fail({method:'GET',status:503});
    const result=await store.createTask({title:'Committed'});assert.match(result.syncWarning,/refresh failed/);
    assert.equal(store.data.tasks.at(-1).title,'Committed');assert.equal(store.data._revision,null);
    assert.equal(requests.filter(r=>r.method==='POST').length,1);
  });
  test(label('team creation omits null recurrence without changing update clearing semantics'),async()=>{
    const {store,requests}=setup();await store.createTask({title:'Team task',recurring:null,nextRecurrenceAt:null});
    assert.equal(Object.hasOwn(requests[0].body,'recurring'),false);assert.equal(Object.hasOwn(requests[0].body,'next_recurrence_at_ms'),false);
    await store.updateTask('t_existing',{recurring:null});assert.equal(requests.at(-2).body.recurring,null);
  });
  test(label('status-only modal change does not send an empty description patch'),async()=>{
    const {store,server,requests}=setup();server.tasks[0].description=null;store.data.tasks[0].desc=null;store._baseline=clone(store.data);
    await store.saveTaskChanges('t_existing',{desc:'',status:'review'});
    assert.deepEqual(requests.filter(r=>r.method==='PATCH').map(r=>r.url),['/api/tasks/t_existing/status']);
  });
  test(label('failed atomic completion changes neither status nor progress'),async()=>{
    const {store,fail,requests}=setup();fail({url:'/api/tasks/t_existing',status:422});
    await assert.rejects(store.saveTaskChanges('t_existing',{status:'done',progress:'completed'}));
    assert.equal(store.data.tasks[0].status,'todo');assert.equal(store.data.tasks[0].progress,'just_started');assert.equal(requests.length,1);
    assert.equal(requests.filter(r=>r.method==='PUT').length,0);
  });
  test(label('session refresh retries only a definite CSRF failure'),async()=>{
    const {store,requests,meta,fail}=setup();fail({status:419});await store.setTaskProgress('t_existing','50');
    const writes=requests.filter(r=>r.method==='PATCH');assert.equal(writes.length,2);
    assert.equal(writes[0].headers['X-CSRF-TOKEN'],'csrf-one');assert.equal(writes[1].headers['X-CSRF-TOKEN'],'csrf-two');
    assert.equal(writes[1].credentials,'same-origin');assert.equal(meta.content,'csrf-two');
  });
}
