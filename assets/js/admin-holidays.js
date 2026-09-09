(function(root,factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else root.AdminHolidays=api;})(globalThis,function(){
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function create({request,getUser}){
    let host=null,owner=null,epoch=0,rows=[],busy=false,error='',draft={};
    const active=t=>t===epoch&&getUser()?.role==='admin'&&getUser()?.id===owner;
    function paint(){
      if(!host)return;
      const disabled=busy?' disabled':'';
      host.innerHTML=`<div class="section-head"><div><h2>Holidays</h2><p class="muted small">Manage office holidays. Use the same start and end date for a one-day holiday.</p></div><button class="btn-ghost small" data-refresh${disabled}>Refresh</button></div>
      <form data-holiday-form><div class="admin-attendance-filters"><label>Name<input name="name" maxlength="255" value="${esc(draft.name)}" required${disabled}></label><label>Start date<input type="date" name="start_date" value="${esc(draft.start_date)}" required${disabled}></label><label>End date<input type="date" name="end_date" value="${esc(draft.end_date)}" required${disabled}></label></div><div class="field"><label>Notes (optional)<textarea name="notes" maxlength="2000"${disabled}>${esc(draft.notes)}</textarea></label></div><button class="btn"${disabled}>${draft.id?'Save changes':'Add holiday'}</button> <button type="button" class="btn-ghost" data-cancel${disabled}>Clear / Cancel</button></form>
      ${error?`<p class="attendance-error" role="alert">${esc(error)}</p>`:''}<p role="status">${busy?'Loading…':''}</p>
      <div class="admin-attendance-table"><table><thead><tr><th>Name</th><th>Start date</th><th>End date</th><th>Notes</th><th>Actions</th></tr></thead><tbody>${rows.map(r=>`<tr><td>${esc(r.name)}</td><td>${esc(r.start_date)}</td><td>${esc(r.end_date)}</td><td>${esc(r.notes)}</td><td><button class="btn-ghost small" data-edit="${esc(r.id)}"${disabled}>Edit</button><button class="btn-ghost small" data-delete="${esc(r.id)}"${disabled}>Delete</button></td></tr>`).join('')||'<tr><td colspan="5">No holidays.</td></tr>'}</tbody></table></div>`;
      host.setAttribute('aria-busy',String(busy));
      host.oninput=e=>{if(['name','start_date','end_date','notes'].includes(e.target.name))draft[e.target.name]=e.target.value;};
      host.onsubmit=e=>{e.preventDefault();save();};
      host.onclick=e=>{const b=e.target.closest('button');if(!b||busy)return;if(b.hasAttribute('data-refresh'))refresh();if(b.hasAttribute('data-cancel')){draft={};paint();}if(b.dataset.edit){draft={...rows.find(r=>r.id===b.dataset.edit)};paint();}if(b.dataset.delete&&globalThis.confirm('Delete this holiday?'))remove(b.dataset.delete);};
    }
    async function run(operation){if(busy||!active(epoch))return;const token=epoch;busy=true;error='';paint();try{await operation(token);}catch(e){if(active(token))error=e.message||'Holiday operation failed.';}finally{if(active(token)){busy=false;paint();}}}
    async function read(token){const result=await request('/api/admin/holidays','GET');if(active(token))rows=result.holidays;}
    function refresh(){return run(read);}
    function save(){const values={name:draft.name,start_date:draft.start_date,end_date:draft.end_date,notes:draft.notes||null},id=draft.id;return run(async token=>{await request('/api/admin/holidays'+(id?'/'+encodeURIComponent(id):''),id?'PUT':'POST',values);if(active(token)){draft={};await read(token);}});}
    function remove(id){return run(async token=>{await request('/api/admin/holidays/'+encodeURIComponent(id),'DELETE');if(active(token)){if(draft.id===id)draft={};await read(token);}});}
    function reset(){epoch++;if(host){host.onclick=null;host.oninput=null;host.onsubmit=null;}host=null;owner=null;rows=[];draft={};busy=false;error='';}
    function mount(element){const u=getUser();if(!element||u?.role!=='admin'){reset();return;}if(owner!==u.id){reset();owner=u.id;}host=element;paint();return refresh();}
    return {mount,refresh,save,remove,reset,unmount:reset};
  }
  return {create};
});
