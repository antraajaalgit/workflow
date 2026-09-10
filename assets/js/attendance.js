/* Employee attendance: server state controls every action and attendance decision. */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.AttendanceCard = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function locate() {
    return new Promise((resolve, reject) => {
      if (globalThis.isSecureContext === false) return reject(new Error('Location requires HTTPS or localhost. Open Karya using a secure connection.'));
      if (!globalThis.navigator?.geolocation) return reject(new Error('Location is unavailable in this browser. Use a browser with location support.'));
      navigator.geolocation.getCurrentPosition(position => resolve({
        latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy
      }), error => reject(new Error({
        1: 'Location permission denied. Allow location access for Karya in your browser settings, then retry.',
        2: 'Your location is unavailable. Check your device location services and try again.',
        3: 'Location request timed out. Try again where your device can obtain a location.'
      }[error.code] || 'Unable to obtain your location. Please try again.')),
      {enableHighAccuracy: true, timeout: 15000, maximumAge: 0});
    });
  }

  function time(value, timezone) {
    if (!value) return '—';
    try {
      return new Intl.DateTimeFormat('en-IN', {timeZone: timezone || 'Asia/Kolkata', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true}).format(new Date(value));
    } catch (_) { return 'Unavailable'; }
  }

  function create({request, getUser, geolocate = locate}) {
    let host = null, owner = null, epoch = 0, state = null;
    let busy = false, loading = false, error = '', notice = '', phase = '';
    const active = token => token === epoch && getUser()?.role === 'team' && getUser()?.id === owner;
    const valid = value => value && typeof value.can_check_in === 'boolean' && typeof value.can_check_out === 'boolean' && typeof value.attendance_date === 'string';

    function paint() {
      if (!host) return;
      const record = state?.attendance;
      const labels = {present:'Present', absent:'Absent', paid_leave:'Paid Leave', unpaid_leave:'Unpaid Leave', weekly_off:'Office Closed – Weekly Off'};
      const status = state?.day_type==='holiday' && !record?.check_in_at ? 'Holiday – '+state.holiday_name : record ? (labels[record.status] || 'Attendance unavailable') :
        state?.approved_leave_type ? `Approved ${state.approved_leave_type === 'paid' ? 'Paid' : 'Unpaid'} Leave` :
        state?.day_type === 'weekly_off' ? 'Office Closed – Weekly Off' : state ? 'Not checked in' : 'Attendance unavailable';
      const worked = record?.check_out_at && Number.isFinite(record.worked_minutes)
        ? `${Math.floor(record.worked_minutes / 60)}h ${record.worked_minutes % 60}m` : '—';
      const disabled = busy || loading ? ' disabled' : '';
      host.innerHTML = `<div class="section-head"><div><h2 id="attendance-heading">Today’s attendance</h2><p class="muted small">${escape(state ? `${state.attendance_date} · ${state.timezone}` : 'All attendance times are in IST')}</p></div>
        <button type="button" class="btn-ghost small" data-attendance-action="refresh"${disabled}>Refresh</button></div>
        <div class="attendance-status"><strong>${escape(loading && !state ? 'Loading attendance…' : status)}</strong>
        ${record?.status === 'present' ? `<span class="pill">${record.is_late ? 'Late' : 'On Time'}</span>` : ''}
        ${record?.is_early_checkout ? '<span class="pill">Early Checkout</span>' : ''}
        ${state?.day_type === 'overtime' ? '<span class="pill">Overtime day</span>' : ''}</div>
        <dl class="attendance-details"><div><dt>Check In</dt><dd>${escape(time(record?.check_in_at, state?.timezone))}</dd></div>
        <div><dt>Check Out</dt><dd>${escape(time(record?.check_out_at, state?.timezone))}</dd></div>
        <div><dt>Worked time</dt><dd>${escape(worked)}</dd></div></dl>
        ${state?.message ? `<p class="muted small attendance-message">${escape(state.message)}</p>` : ''}
        ${error ? `<p class="attendance-error" role="alert">${escape(error)}</p>` : ''}
        <p class="small attendance-feedback" role="status" aria-live="polite">${escape(busy ? phase : notice)}</p>
        <div class="attendance-actions">
        ${state?.can_check_in === true ? `<button type="button" class="btn" data-attendance-action="check-in"${disabled}>Check In</button>` : ''}
        ${state?.can_check_out === true ? `<button type="button" class="btn" data-attendance-action="check-out"${disabled}>Check Out</button>` : ''}
        ${state?.can_check_in || state?.can_check_out ? '<span class="muted small">Your location is requested when you check in or out.</span>' : ''}</div>`;
      host.setAttribute('aria-busy', String(busy || loading));
      host.onclick = event => {
        const button = event.target.closest('[data-attendance-action]');
        if (!button || !host?.contains(button)) return;
        if (button.dataset.attendanceAction === 'refresh') refresh();
        else act(button.dataset.attendanceAction);
      };
    }

    async function read(token) {
      loading = true; paint();
      try {
        const result = await request('/api/attendance/today', 'GET');
        if (!active(token)) return;
        if (!valid(result)) throw new Error('Unable to read attendance. Please refresh.');
        state = result;
      } catch (e) {
        if (active(token)) {
          state = null;
          error = error ? `${error} Attendance could not be refreshed; use Refresh before trying again.` : (e.message || 'Attendance is unavailable. Please refresh.');
        }
      } finally {
        if (active(token)) { loading = false; paint(); }
      }
    }

    async function refresh() {
      if (busy || loading || !active(epoch)) return;
      error = ''; notice = '';
      await read(epoch);
    }

    async function act(action) {
      if (!['check-in', 'check-out'].includes(action) || busy || loading || !active(epoch)) return;
      if (state?.[action === 'check-in' ? 'can_check_in' : 'can_check_out'] !== true) return;
      const token = epoch;
      busy = true; error = ''; notice = ''; phase = 'Getting your location…'; paint();
      let sent = false;
      try {
        let location;
        try { location = await geolocate(); } catch (_) { location = null; }
        if (!active(token)) return;
        phase = action === 'check-in' ? 'Checking in…' : 'Checking out…'; paint();
        sent = true;
        const result = await request(`/api/attendance/${action}`, 'POST', {
          latitude: location?.latitude, longitude: location?.longitude, accuracy: location?.accuracy
        });
        if (!active(token)) return;
        notice = action === 'check-in' ? 'Checked in successfully.' : 'Checked out successfully.';
        if (valid(result)) state = result;
        phase = 'Refreshing attendance…';
        await read(token);
      } catch (e) {
        if (active(token)) {
          error = e.message || 'Attendance could not be saved. Please refresh before retrying.';
          // A lost response may still have committed. Re-read; never blindly resubmit.
          if (sent) await read(token);
        }
      } finally {
        if (active(token)) { busy = false; phase = ''; paint(); }
      }
    }

    function reset() {
      epoch++; if (host) { host.innerHTML = ''; host.onclick = null; }
      host = null; owner = null; state = null; busy = false; loading = false; error = ''; notice = ''; phase = '';
    }

    function mount(element) {
      const user = getUser();
      if (!element || user?.role !== 'team') { reset(); return; }
      if (owner !== user.id) { reset(); owner = user.id; }
      host = element; paint();
      return refresh();
    }

    return {mount, refresh, act, reset, unmount() { if (host) host.onclick = null; host = null; }};
  }

  return {create, locate};
});
