const {test}=require('node:test');
const assert=require('node:assert/strict');

for(const path of ['assets/js/admin-leave.js','public/assets/js/admin-leave.js']){
  const {create}=require('../../'+path);

  test(path+': shows the current paid leave remaining out of the annual entitlement',async()=>{
    const host={innerHTML:'',setAttribute(){},contains(){return true;}};
    const screen=create({
      getUser:()=>({id:'admin',role:'admin'}),
      request:async()=>({requests:[{
        id:'leave-1',user_name:'Employee One',user_email:'one@example.test',
        start_date:'2026-10-12',end_date:'2026-10-12',qualifying_days:1,
        reason:'Appointment',status:'pending',paid_leave_days:0,unpaid_leave_days:0,
        paid_leave_year:2026,paid_leave_entitlement:12,paid_leave_remaining:5
      }]})
    });

    await screen.mount(host);

    assert.match(host.innerHTML,/<th>Paid Left<\/th>/);
    assert.match(host.innerHTML,/<td>5 \/ 12<\/td>/);
  });

  test(path+': empty-state row spans the added paid-left column',async()=>{
    const host={innerHTML:'',setAttribute(){},contains(){return true;}};
    const screen=create({
      getUser:()=>({id:'admin',role:'admin'}),
      request:async()=>({requests:[]})
    });

    await screen.mount(host);

    assert.match(host.innerHTML,/colspan="10"/);
  });
}
