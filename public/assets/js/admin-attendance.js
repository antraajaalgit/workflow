(function(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.AdminAttendance = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function() {
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const labels = {present:'Present', absent:'Absent', not_checked_in:'Not checked in', weekly_off:'Office Closed – Weekly Off', paid_leave:'Approved Paid Leave', unpaid_leave:'Approved Unpaid Leave'};
  function clock(value, timezone) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-IN', {timeZone:timezone, hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true}).format(new Date(value));
  }
  function create({request, getUser}) {
    let host=null, owner=null, generation=0, data=null, date='', employee='', notes='', busy=false, error='', message='';
    const active = token => token===generation && getUser()?.role==='admin' && getUser()?.id===owner;
    function paint() {
      if (!host) return;
      const disabled=busy?' disabled':'';
      const rows=(data?.rows||[]).map(row=>`<tr><th scope="row">${esc(row.name)}</th>
        <td>${esc(labels[row.status]||row.status)}${row.approved_leave_type && row.status!==row.approved_leave_type+'_leave'?`<div class="small">Approved ${row.approved_leave_type==='paid'?'Paid':'Unpaid'} Leave</div>`:''}</td>
        <td>${esc(clock(row.check_in_at,data.timezone))}</td><td>${esc(clock(row.check_out_at,data.timezone))}</td>
        <td>${row.is_late?'Late':'—'}</td><td>${row.is_early_checkout?'Early Checkout':'—'}</td>
        <td>${row.worked_minutes===null?'—':`${Math.floor(row.worked_minutes/60)}h ${row.worked_minutes%60}m`}</td>
        <td>${row.has_overtime_override?'Overtime authorized':row.override?'Override revoked':row.is_weekly_off?'Weekly Off':'Working day'}
          ${row.override?.notes?`<div class="small muted">${esc(row.override.notes)}</div>`:''}</td>
        <td>${row.can_authorize_override?`<button type="button" class="btn small" data-overtime-create="${esc(row.user_id)}"${disabled}>Authorize overtime</button>`:''}
          ${row.can_revoke_override?`<button type="button" class="btn-ghost small" data-overtime-revoke="${esc(row.override.id)}"${disabled}>Revoke overtime</button>`:''}</td></tr>`).join('');
      host.innerHTML=`<div class="section-head"><div><h2>Team attendance</h2><p class="muted small">${esc(data?.timezone||'Asia/Kolkata')} · Review attendance and authorize weekly-off work.</p></div></div>
        <form data-attendance-filters class="admin-attendance-filters"><label>Date<input name="date" type="date" value="${esc(date)}" required${disabled}></label>
          <label>Team member<select name="employee"${disabled}><option value="">All team members</option>${(data?.employees||[]).map(user=>`<option value="${esc(user.id)}"${employee===user.id?' selected':''}>${esc(user.name)}</option>`).join('')}</select></label>
          <button type="submit" class="btn"${disabled}>Apply filters / Refresh</button></form>
        <label class="admin-overtime-note">Overtime note (optional)<input name="overtime-note" maxlength="2000" value="${esc(notes)}"${disabled}></label>
        <p class="muted small">Overtime controls apply only to the employee in that row and the selected date. Revoked authorizations remain in the audit history.</p>
        ${error?`<p class="attendance-error" role="alert">${esc(error)}</p>`:''}
        <p role="status" aria-live="polite" class="attendance-feedback small">${esc(busy?'Loading attendance…':message)}</p>
        <div class="admin-attendance-table"><table><caption class="muted small">Attendance for ${esc(data?.attendance_date||'selected date')}</caption>
          <thead><tr><th>Employee</th><th>Status</th><th>Check In</th><th>Check Out</th><th>Late</th><th>Early checkout</th><th>Worked time</th><th>Schedule / Overtime</th><th>Actions</th></tr></thead>
          <tbody>${rows||`<tr><td colspan="9">${busy?'Loading…':data?'No team members match this filter.':'Attendance unavailable. Use Refresh to retry.'}</td></tr>`}</tbody></table></div>`;
      host.setAttribute('aria-busy',String(busy));
      host.onsubmit=event=>{event.preventDefault();if(busy)return;const fields=event.target.elements;filter(fields.date.value,fields.employee.value);};
      host.oninput=event=>{if(event.target.name==='overtime-note')notes=event.target.value;};
      host.onclick=event=>{
        const button=event.target.closest('[data-overtime-create], [data-overtime-revoke]');
        if(!button||!host?.contains(button))return;
        if(button.dataset.overtimeCreate)change('create',button.dataset.overtimeCreate);
        else change('revoke',button.dataset.overtimeRevoke);
      };
    }
    async function read(token) {
      const query=new URLSearchParams();if(date)query.set('date',date);if(employee)query.set('user_id',employee);
      const result=await request('/api/admin/attendance'+(query.size?'?'+query:''),'GET');
      if(!active(token))return;
      if(!Array.isArray(result.rows)||!Array.isArray(result.employees)||!result.attendance_date)throw new Error('Attendance could not be read. Please refresh.');
      data=result;date=result.attendance_date;
    }
    async function refresh() {
      if(busy||!active(generation))return;
      const token=generation;busy=true;error='';message='';paint();
      try{await read(token);}catch(e){if(active(token)){data=null;error=e.message||'Attendance unavailable.';}}
      finally{if(active(token)){busy=false;paint();}}
    }
    async function filter(nextDate,nextEmployee) {
      if(busy)return;
      date=nextDate;employee=nextEmployee;data=null;
      await refresh();
    }
    async function change(action,id) {
      if(busy||!active(generation))return;
      const row=data?.rows.find(row=>action==='create'?row.user_id===id:row.override?.id===id);
      if(!row||(action==='create'?!row.can_authorize_override:!row.can_revoke_override))return;
      const token=generation;busy=true;error='';message='';paint();
      try{
        const result=action==='create'
          ?await request('/api/admin/attendance/overrides','POST',{user_id:row.user_id,work_date:data.attendance_date,notes:notes||null})
          :await request('/api/admin/attendance/overrides/'+encodeURIComponent(id),'DELETE',{notes:notes||null});
        if(!active(token))return;message=result.message;notes='';
      }catch(e){if(active(token))error=e.message||'Overtime could not be updated.';}
      finally{
        if(active(token)){
          try{await read(token);}catch(e){if(active(token)){data=null;error=(error?error+' ':'')+'Refresh attendance before trying again.';}}
          if(active(token)){busy=false;paint();}
        }
      }
    }
    function reset(){generation++;if(host){host.innerHTML='';host.onclick=null;host.oninput=null;host.onsubmit=null;}host=null;owner=null;data=null;date='';employee='';notes='';busy=false;error='';message='';}
    function mount(element){const user=getUser();if(!element||user?.role!=='admin'){reset();return;}if(owner!==user.id){reset();owner=user.id;}host=element;paint();return refresh();}
    return {mount,refresh,filter,change,reset,unmount(){if(host){host.onclick=null;host.oninput=null;host.onsubmit=null;}host=null;}};
  }
  return {create};
});
