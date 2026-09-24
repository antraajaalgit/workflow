const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');
  test(`${file}: assignment fields show requested admin names and no role or department suffix`,()=>{
    const start=code.indexOf('const ADMIN_ASSIGNEE_NAMES');
    const end=code.indexOf('const clientById',start);
    const ctx=vm.createContext({esc:value=>String(value)});
    vm.runInContext(`${code.slice(start,end)};this.label=assigneeLabel;`,ctx);
    assert.equal(ctx.label({id:'u_admin_agam',name:'Agam Bahri',role:'admin'}),'Agam Bahri');
    assert.equal(ctx.label({id:'u_admin_sales',name:'Sales Admin',role:'admin'}),'Jagmeet Bahri');
    assert.equal(ctx.label({id:'u_admin_ceo',name:'CEO Admin',role:'admin'}),'Deepika Bahri');
    assert.equal(ctx.label({id:'team-jack',name:'jack',role:'team',dept:'Design'}),'jack');
    assert.equal(ctx.label({id:'another-admin',name:'Priya',role:'admin'}),'Priya');
  });
}
