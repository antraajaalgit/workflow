const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const response=(status,data)=>({ok:status>=200&&status<300,status,json:async()=>data});

function setup(fetch){
  const meta={content:'csrf-one'};
  const context=vm.createContext({fetch,document:{querySelector:()=>meta},navigator:{onLine:true},console:{warn(){}}});
  vm.runInContext(fs.readFileSync('public/assets/js/store.js','utf8')+'\nglobalThis.store=Store;',context);
  return {store:context.store,meta};
}

function setupAuthUi({load}){
  const makeClassList=initial=>{
    const values=new Set(initial);
    return {add:value=>values.add(value),remove:value=>values.delete(value),contains:value=>values.has(value),toggle(value,force){force?values.add(value):values.delete(value);}};
  };
  const elements={
    '#login-submit':{disabled:false,textContent:'Sign in',classList:makeClassList([])},
    '#login-error':{textContent:'',classList:makeClassList(['hidden'])},
    '#login-retry':{disabled:false,textContent:'Retry',classList:makeClassList(['hidden'])},
    '#login-password':{value:'secret',classList:makeClassList([])},
    '#login':{classList:makeClassList([])},
    '#app':{classList:makeClassList(['hidden'])},
    '#login-form':{reset(){}},
  };
  const user={id:'one',role:'team'},calls=[];
  const context=vm.createContext({
    $:selector=>elements[selector],
    Store:{data:null,signIn:async()=>user,load,currentSession:async()=>({userId:'one',user})},
    session:null,pendingSession:null,recurringSuspended:true,route:'dashboard',
    userById:()=>user,announceSessionChange:type=>calls.push(type),
    buildNav:()=>calls.push('nav'),renderWho:()=>calls.push('who'),updateBell:()=>calls.push('bell'),render:()=>calls.push('render'),openRequestedTask:()=>calls.push('deep-link'),
    employeeAttendance:null,employeeLeave:null,adminAttendance:null,adminLeave:null,adminHolidays:null,
    setTimeout(){},runRecurringTasks(){},
  });
  const source=fs.readFileSync('public/assets/js/app.js','utf8');
  const auth=source.slice(source.indexOf('async function signIn'),source.indexOf('async function signOut'));
  vm.runInContext(auth+'\nglobalThis.authUi={signIn,retrySessionRecovery};',context);
  return {context,elements,calls,user};
}

test('successful login refreshes CSRF and loads dashboard state',async()=>{
  const requests=[];
  const {store,meta}=setup(async(url,options={})=>{
    requests.push({url,options});
    if(url==='/api/session')return response(200,{userId:'one',user:{id:'one'},csrfToken:'csrf-two'});
    return response(200,{_revision:'r1',users:[{id:'one'}],tasks:[]});
  });
  const user=await store.signIn('one@example.test','secret');
  const state=await store.load();
  assert.equal(user.id,'one');assert.equal(state._revision,'r1');assert.equal(meta.content,'csrf-two');
  assert.deepEqual(requests.map(item=>[item.options.method||'GET',item.url]),[['POST','/api/session'],['GET','/api/state']]);
});

test('successful authentication initializes and reveals the dashboard only after state loads',async()=>{
  const order=[];
  const ui=setupAuthUi({load:async()=>order.push('state')});
  ui.context.buildNav=()=>order.push('dashboard');
  await ui.context.authUi.signIn('one@example.test','secret');
  assert.deepEqual(order,['state','dashboard']);
  assert.equal(ui.context.session.id,'one');assert.equal(ui.context.pendingSession,null);
  assert.equal(ui.elements['#app'].classList.contains('hidden'),false);
  assert.equal(ui.elements['#login'].classList.contains('hidden'),true);
});

test('post-login state failure stays recoverable without exposing a ready global session',async()=>{
  let attempts=0;
  const ui=setupAuthUi({load:async()=>{if(++attempts===1)throw Object.assign(new Error('Temporary network failure'),{kind:'network'});}});
  await ui.context.authUi.signIn('one@example.test','secret');
  assert.equal(ui.context.session,null);assert.equal(ui.context.pendingSession.id,'one');
  assert.equal(ui.elements['#app'].classList.contains('hidden'),true);
  assert.equal(ui.elements['#login-retry'].classList.contains('hidden'),false);
  assert.match(ui.elements['#login-error'].textContent,/signed in.*Temporary network failure/i);

  await ui.context.authUi.retrySessionRecovery();
  assert.equal(attempts,2);assert.equal(ui.context.session.id,'one');
  assert.equal(ui.elements['#app'].classList.contains('hidden'),false);
});

test('authentication success remains distinguishable from a failed state load and recovery',async()=>{
  let stateAttempts=0;
  const {store}=setup(async(url)=>{
    if(url==='/api/session')return response(200,{userId:'one',user:{id:'one'},csrfToken:'csrf-two'});
    if(++stateAttempts===1)throw new Error('offline');
    return response(200,{_revision:'r1',users:[{id:'one'}],tasks:[]});
  });
  const authenticated=await store.signIn('one@example.test','secret');
  await assert.rejects(store.load(),error=>error.kind==='network'&&error.retryable===true);
  assert.equal(authenticated.id,'one');assert.equal(store.data,null);
  await store.load();assert.equal(store.data.users[0].id,'one');assert.equal(stateAttempts,2);
});

test('expired state request is a non-retryable authentication error',async()=>{
  const {store}=setup(async()=>response(401,{message:'Please sign in.'}));
  await assert.rejects(store.load(),error=>error.status===401&&error.kind==='auth'&&error.retryable===false);
});

test('login refreshes a stale CSRF token once after a definite 419',async()=>{
  const requests=[];
  const {store,meta}=setup(async(url,options={})=>{
    requests.push({url,method:options.method||'GET',token:options.headers?.['X-CSRF-TOKEN']});
    if(requests.length===1)return response(419,{message:'Page expired'});
    if(requests.length===2)return response(200,{userId:null,user:null,csrfToken:'csrf-two'});
    return response(200,{userId:'one',user:{id:'one'},csrfToken:'csrf-three'});
  });
  const user=await store.signIn('one@example.test','secret');
  assert.equal(user.id,'one');assert.equal(meta.content,'csrf-three');
  assert.deepEqual(requests.map(item=>[item.method,item.url,item.token]),[
    ['POST','/api/session','csrf-one'],['GET','/api/session',undefined],['POST','/api/session','csrf-two'],
  ]);
});

test('recurring generation deduplicates in-flight work and never retries a 419 POST',async()=>{
  let requests=0,release;
  const held=new Promise(resolve=>{release=resolve;});
  const first=setup(async()=>{requests++;await held;return response(200,{created:0});}).store;
  const one=first.generateRecurringTasks(),two=first.generateRecurringTasks();
  await new Promise(resolve=>setImmediate(resolve));assert.equal(requests,1);
  release();await Promise.all([one,two]);

  requests=0;
  const second=setup(async()=>{requests++;return response(419,{message:'Page expired'});}).store;
  await assert.rejects(second.generateRecurringTasks(),error=>error.status===419&&error.kind==='csrf');
  assert.equal(requests,1);
});

test('served app keeps authentication pending until state loads and provides recoverable startup',()=>{
  const source=fs.readFileSync('public/assets/js/app.js','utf8');
  const initializer=source.slice(source.indexOf('async function initializeAuthenticatedApp'),source.indexOf('async function retrySessionRecovery'));
  const boot=source.slice(source.indexOf('(async function boot'));
  assert.ok(initializer.indexOf('await Store.load()')<initializer.indexOf('session=loadedUser'));
  assert.match(initializer,/Retry dashboard/);
  assert.doesNotMatch(source,/document\.body\.innerHTML/);
  assert.doesNotMatch(boot,/await Store\.generateRecurringTasks/);
  assert.match(source,/BroadcastChannel\('karya-session-v1'\)/);
  assert.match(source,/navigator\.locks\.request\('karya-recurring-task-generation'/);
  assert.match(source,/Date\.now\(\)-last<55000/);
  assert.match(source,/if\(error\.status===419\)[\s\S]*await Store\.currentSession\(\)/);
});
