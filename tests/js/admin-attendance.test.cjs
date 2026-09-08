const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const base=()=>({attendance_date:'2026-09-13',timezone:'Asia/Kolkata',employees:[{id:'one',name:'Employee One'},{id:'two',name:'Employee Two'}],rows:[{
  user_id:'one',name:'Employee One',status:'weekly_off',check_in_at:null,check_out_at:null,is_late:false,is_early_checkout:false,
  worked_minutes:null,is_weekly_off:true,has_overtime_override:false,override:null,can_authorize_override:true,can_revoke_override:false
}]});
for(const path of ['assets/js/admin-attendance.js','public/assets/js/admin-attendance.js']){
  const {create}=require('../../'+path);
  function setup(role='admin'){
    const calls=[];let value=base();
    const host={innerHTML:'',setAttribute(){},contains(){return true;}};
    const screen=create({getUser:()=>({id:'admin',role}),request:async(url,method,payload)=>{
      calls.push({url,method,payload});if(method==='POST')value.rows[0]={...value.rows[0],has_overtime_override:true,override:{id:'ov1',active:true},can_authorize_override:false,can_revoke_override:true};
      if(method==='DELETE')value.rows[0]={...value.rows[0],has_overtime_override:false,override:{id:'ov1',active:false},can_revoke_override:false};
      return method==='GET'?value:{message:'Saved'};
    }});
    return {screen,host,calls,setValue(v){value=v;}};
  }
  test(path+': admin loads server date and filters without using device today',async()=>{
    const s=setup();await s.screen.mount(s.host);assert.equal(s.calls[0].url,'/api/admin/attendance');
    assert.match(s.host.innerHTML,/Employee One/);assert.match(s.host.innerHTML,/Office Closed – Weekly Off/);
    await s.screen.filter('2026-09-12','two');assert.equal(s.calls[1].url,'/api/admin/attendance?date=2026-09-12&user_id=two');
  });
  test(path+': creates selected employee/date override and revokes with refresh',async()=>{
    const s=setup();await s.screen.mount(s.host);await s.screen.change('create','one');
    assert.deepEqual(s.calls[1],{url:'/api/admin/attendance/overrides',method:'POST',payload:{user_id:'one',work_date:'2026-09-13',notes:null}});
    assert.match(s.host.innerHTML,/Overtime authorized/);assert.match(s.host.innerHTML,/Revoke overtime/);
    await s.screen.change('revoke','ov1');assert.equal(s.calls[3].method,'DELETE');assert.equal(s.calls[3].url,'/api/admin/attendance/overrides/ov1');
    assert.match(s.host.innerHTML,/Override revoked/);assert.doesNotMatch(s.host.innerHTML,/data-overtime-create/);
  });
  test(path+': non-admin cannot mount or issue mutation requests',async()=>{
    for(const role of ['team','client']){const s=setup(role);await s.screen.mount(s.host);await s.screen.change('create','one');assert.equal(s.calls.length,0);assert.equal(s.host.innerHTML,'');}
  });
  test(path+': shows attendance flags, worked time and escapes names',async()=>{
    const s=setup();const value=base();Object.assign(value.rows[0],{name:'<script>bad</script>',status:'present',is_late:true,is_early_checkout:true,worked_minutes:420,check_in_at:'2026-09-13T10:00:00+05:30',can_authorize_override:false});s.setValue(value);
    await s.screen.mount(s.host);assert.match(s.host.innerHTML,/Present/);assert.match(s.host.innerHTML,/Early Checkout/);assert.match(s.host.innerHTML,/7h 0m/);assert.match(s.host.innerHTML,/10:00:00/);assert.doesNotMatch(s.host.innerHTML,/<script>/);
  });
  test(path+': in-flight mutation blocks repeated clicks',async()=>{
    let finish;const pending=new Promise(resolve=>{finish=resolve;});let writes=0;
    const screen=create({getUser:()=>({id:'admin',role:'admin'}),request:async(_url,method)=>{if(method==='GET')return base();writes++;return pending;}});
    const host={innerHTML:'',setAttribute(){}};await screen.mount(host);const action=screen.change('create','one');await screen.change('create','one');assert.equal(writes,1);assert.match(host.innerHTML,/data-overtime-create="one" disabled/);finish({message:'Saved'});await action;
  });
}
test('admin attendance navigation is limited to admin in both app entrypoints',()=>{
  for(const file of ['assets/js/app.js','public/assets/js/app.js']){
    const source=fs.readFileSync(file,'utf8');const nav=source.slice(source.indexOf('const NAV ='),source.indexOf('function buildNav'));
    assert.match(nav.split('team: [')[0],/id:'admin-attendance'/);assert.doesNotMatch(nav.split('team: [')[1],/id:'admin-attendance'/);
    assert.match(source,/route === 'admin-attendance' && session.role === 'admin'/);
  }
});
