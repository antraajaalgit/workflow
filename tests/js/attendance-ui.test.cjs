const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const before = () => ({attendance_date:'2026-09-08', timezone:'Asia/Kolkata', day_type:'working_day',
  is_working_day:true, is_weekly_off:false, has_overtime_override:false, approved_leave_type:null,
  attendance:null, can_check_in:true, can_check_out:false, message:'You have not checked in today.'});
const checkedIn = () => ({...before(), can_check_in:false, can_check_out:true, attendance:{
  status:'present', check_in_at:'2026-09-08T09:30:00+05:30', check_out_at:null, is_late:false, is_early_checkout:false, worked_minutes:null}});
const checkedOut = () => ({...checkedIn(), can_check_out:false, attendance:{...checkedIn().attendance,
  check_out_at:'2026-09-08T17:55:00+05:30', is_early_checkout:true, worked_minutes:505}});
const coordinates = {latitude:1.234, longitude:2.345, accuracy:8}; // Synthetic browser result only.
const deferred = () => {let resolve, reject; const promise = new Promise((ok, bad) => {resolve=ok;reject=bad;}); return {promise,resolve,reject};};
const host = () => ({innerHTML:'', attributes:{}, setAttribute(k,v){this.attributes[k]=v;}, contains(){return true;}});

for (const file of ['assets/js/attendance.js', 'public/assets/js/attendance.js']) {
  const source = fs.readFileSync(file, 'utf8');
  function setup({initial=before(), geo, post, secure=true, role='team'}={}) {
    const calls=[], positions=[];
    const context=vm.createContext({module:{exports:{}}, isSecureContext:secure, navigator:{geolocation:{getCurrentPosition(ok,bad,options){
      positions.push(options); if (geo) geo(ok,bad); else ok({coords:coordinates});
    }}}, Intl, Date});
    vm.runInContext(source,context);
    let current=initial, user={id:'employee-one',role};
    const request=async(url,method,payload)=>{
      calls.push({url,method,payload});
      if(method==='GET') return current;
      current=post ? await post(url,payload) : url.endsWith('check-in') ? checkedIn() : checkedOut();
      return current;
    };
    const card=context.module.exports.create({request,getUser:()=>user});
    const element=host();
    return {card,element,calls,positions,setUser(value){user=value;}};
  }

  test(file+': fetches on mount; Check In visibility follows backend only',async()=>{
    const {card,element,calls,positions}=setup();await card.mount(element);
    assert.equal(calls[0].url,'/api/attendance/today');assert.equal(calls[0].method,'GET');
    assert.match(element.innerHTML,/data-attendance-action="check-in"/);
    assert.doesNotMatch(element.innerHTML,/data-attendance-action="check-out"/);
    assert.match(element.innerHTML,/Not checked in/);assert.equal(positions.length,0);
  });
  test(file+': Check Out and completed state visibility, server times and worked duration',async()=>{
    const s=setup({initial:checkedIn()});await s.card.mount(s.element);
    assert.match(s.element.innerHTML,/data-attendance-action="check-out"/);
    assert.doesNotMatch(s.element.innerHTML,/data-attendance-action="check-in"/);
    assert.match(s.element.innerHTML,/09:30:00/);assert.match(s.element.innerHTML,/On Time/);
    await s.card.act('check-out');
    assert.doesNotMatch(s.element.innerHTML,/data-attendance-action="check-(in|out)"/);
    assert.match(s.element.innerHTML,/Early Checkout/);assert.match(s.element.innerHTML,/8h 25m/);
  });
  test(file+': weekly off hides actions; overtime override keeps allowed actions',async()=>{
    const off=setup({initial:{...before(), day_type:'weekly_off', is_working_day:false, is_weekly_off:true,
      can_check_in:false,message:'Office Closed – Weekly Off'}});await off.card.mount(off.element);
    assert.match(off.element.innerHTML,/Office Closed – Weekly Off/);
    assert.doesNotMatch(off.element.innerHTML,/data-attendance-action="check-(in|out)"/);
    const overtime=setup({initial:{...before(),day_type:'overtime',is_weekly_off:true,has_overtime_override:true}});
    await overtime.card.mount(overtime.element);assert.match(overtime.element.innerHTML,/Overtime day/);
    assert.match(overtime.element.innerHTML,/data-attendance-action="check-in"/);
  });
  for(const action of ['check-in','check-out']) test(file+': browser geolocation + successful '+action+' refreshes only attendance',async()=>{
    const s=setup({initial:action==='check-in'?before():checkedIn()});await s.card.mount(s.element);
    await s.card.act(action);
    assert.deepEqual(s.calls.map(c=>c.method),['GET','POST','GET']);
    assert.equal(s.calls[1].url,'/api/attendance/'+action);
    assert.deepEqual(JSON.parse(JSON.stringify(s.calls[1].payload)),coordinates);
    assert.equal(s.positions[0].maximumAge,0);assert.equal(s.positions[0].enableHighAccuracy,true);
    assert.match(s.element.innerHTML,/successfully/);
    assert.doesNotMatch(s.element.innerHTML,/1\.234|2\.345|office_latitude|radius_metres/);
  });
  for(const [code,message] of [[1,/permission denied/],[2,/location is unavailable/],[3,/timed out/]]) {
    test(file+': location failure '+code+' is clear and never sends POST',async()=>{
      const s=setup({geo:(_ok,bad)=>bad({code})});await s.card.mount(s.element);await s.card.act('check-in');
      assert.match(s.element.innerHTML,message);assert.match(s.element.innerHTML,/role="alert"/);
      assert.equal(s.calls.length,1);assert.doesNotMatch(s.element.innerHTML,/data-attendance-action="check-in" disabled/);
    });
  }
  for(const message of ['You are outside the allowed office attendance radius.','Location accuracy is insufficient. Obtain a more accurate location and retry.']) {
    test(file+': backend rejection '+message,async()=>{
      const s=setup({post:()=>{throw new Error(message);}});await s.card.mount(s.element);await s.card.act('check-in');
      assert.ok(s.element.innerHTML.includes(message));assert.deepEqual(s.calls.map(c=>c.method),['GET','POST','GET']);
      assert.doesNotMatch(s.element.innerHTML,/Checked in successfully/);
    });
  }
  test(file+': double click is blocked across geolocation, POST and dashboard redraw',async()=>{
    let acceptLocation; const saving=deferred();
    const s=setup({geo:ok=>{acceptLocation=ok;},post:()=>saving.promise});await s.card.mount(s.element);
    const operation=s.card.act('check-in');await s.card.act('check-in');
    assert.equal(s.positions.length,1);assert.match(s.element.innerHTML,/data-attendance-action="check-in" disabled/);
    const replacement=host();await s.card.mount(replacement);await s.card.act('check-in');
    assert.match(replacement.innerHTML,/data-attendance-action="check-in" disabled/);
    acceptLocation({coords:coordinates});await Promise.resolve();await s.card.act('check-in');
    assert.equal(s.calls.filter(c=>c.method==='POST').length,1);
    saving.resolve(checkedIn());await operation;
    assert.match(replacement.innerHTML,/data-attendance-action="check-out"/);
  });
  test(file+': approved leave and late flags are rendered from the server',async()=>{
    const leave=setup({initial:{...before(),approved_leave_type:'paid',can_check_in:false,message:'Approved Paid Leave'}});
    await leave.card.mount(leave.element);assert.match(leave.element.innerHTML,/Approved Paid Leave/);
    assert.doesNotMatch(leave.element.innerHTML,/data-attendance-action="check-in"/);
    const late=setup({initial:{...checkedIn(),attendance:{...checkedIn().attendance,is_late:true}}});
    await late.card.mount(late.element);assert.match(late.element.innerHTML,/Present/);assert.match(late.element.innerHTML,/>Late</);
  });
  test(file+': signing out while locating cannot send attendance for a new session',async()=>{
    let acceptLocation;const s=setup({geo:ok=>{acceptLocation=ok;}});await s.card.mount(s.element);
    const operation=s.card.act('check-in');s.card.reset();s.setUser({id:'employee-two',role:'team'});
    acceptLocation({coords:coordinates});await operation;
    assert.equal(s.calls.filter(c=>c.method==='POST').length,0);assert.equal(s.element.innerHTML,'');
  });
  test(file+': admins and clients do not fetch or mount attendance',async()=>{
    for(const role of ['admin','client']){const s=setup({role});await s.card.mount(s.element);assert.equal(s.calls.length,0);assert.equal(s.element.innerHTML,'');}
  });
  test(file+': backend message is escaped before rendering',async()=>{
    const s=setup({initial:{...before(),message:'<img src=x onerror=alert(1)>'}});await s.card.mount(s.element);
    assert.doesNotMatch(s.element.innerHTML,/<img/);assert.match(s.element.innerHTML,/&lt;img/);
  });
  test(file+': insecure browser gives actionable location error',async()=>{
    const s=setup({secure:false});await s.card.mount(s.element);await s.card.act('check-in');
    assert.match(s.element.innerHTML,/HTTPS or localhost/);assert.equal(s.calls.length,1);
  });
}

test('both dashboard entrypoints mount attendance for team only and reset on logout',()=>{
  for(const path of ['assets/js/app.js','public/assets/js/app.js']){
    const source=fs.readFileSync(path,'utf8');
    assert.match(source,/session\.role==='team'\?`<section id="employee-attendance"/);
    assert.match(source,/route === 'dashboard' && session\.role === 'team'/);
    assert.match(source,/request: \(\.\.\.args\) => Store\.taskJson\(\.\.\.args\)/);
    assert.match(source,/async function signOut\(\)\{\s+employeeAttendance\?\.reset\(\)/);
  }
  assert.equal(fs.readFileSync('assets/js/attendance.js','utf8'),fs.readFileSync('public/assets/js/attendance.js','utf8'));
  const blade=fs.readFileSync('resources/views/app.blade.php','utf8');
  assert.ok(blade.indexOf('js/attendance.js')<blade.indexOf('js/app.js'));
});
