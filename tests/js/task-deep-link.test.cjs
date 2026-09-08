const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
for(const file of ['assets/js/app.js','public/assets/js/app.js']){
 const source=fs.readFileSync(file,'utf8');
 const helper=source.slice(source.indexOf('function openRequestedTask'),source.indexOf('async function signIn'));
 function setup(search,session={id:'admin',role:'admin'}){
  const opened=[],messages=[],task={id:'t_review',status:'review'};
  const ctx=vm.createContext({session,URLSearchParams,window:{location:{search}},S:()=>({tasks:[task]}),openTask:id=>opened.push(id),toast:m=>messages.push(m)});
  vm.runInContext(helper,ctx);return {ctx,opened,messages,task};
 }
 test(file+': valid email link opens exact task without changing status',()=>{
  const {ctx,opened,task}=setup('?task=t_review');ctx.openRequestedTask();assert.deepEqual(opened,['t_review']);assert.equal(task.status,'review');
 });
 test(file+': link survives login and opens only after authentication',()=>{
  const {ctx,opened}=setup('?task=t_review',null);ctx.openRequestedTask();assert.deepEqual(opened,[]);ctx.session={id:'admin'};ctx.openRequestedTask();assert.deepEqual(opened,['t_review']);
  const login=source.slice(source.indexOf('async function signIn'),source.indexOf('async function signOut'));assert.match(login,/render\(\); openRequestedTask\(\)/);
  if(file.startsWith('public/'))assert.match(source.slice(source.indexOf('async function boot')),/await Store.load\(\);.*openRequestedTask\(\)/);
 });
 for(const query of ['?task=','?task=../bad','?task='+ 'x'.repeat(41),'?task=t_review&task=other','?task=%3Cscript%3E'])test(file+': rejects '+query,()=>{
  const {ctx,opened,messages}=setup(query);ctx.openRequestedTask();assert.deepEqual(opened,[]);assert.deepEqual(messages,['This task link is invalid.']);
 });
 test(file+': deleted task fails gracefully',()=>{const {ctx,opened,messages}=setup('?task=deleted');ctx.openRequestedTask();assert.deepEqual(opened,[]);assert.deepEqual(messages,['This task is no longer available.']);});
 test(file+': ordinary dashboard unaffected',()=>{const {ctx,opened,messages}=setup('');ctx.openRequestedTask();assert.deepEqual(opened,[]);assert.deepEqual(messages,[]);});
}
