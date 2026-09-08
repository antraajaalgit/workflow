(function (root, factory) {
  const api = factory();

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.AdminLeave = api;
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  const escape = value =>
    String(value ?? '').replace(
      /[&<>"']/g,
      c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      })[c]
    );

  function create({ request, getUser }) {
    let host = null;
    let owner = null;
    let generation = 0;

    let requests = [];
    let status = 'pending';

    let loading = false;
    let busyId = null;
    let error = '';
    let message = '';

    const active = token =>
      token === generation &&
      getUser()?.role === 'admin' &&
      getUser()?.id === owner;

    function statusLabel(value) {
      return {
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected'
      }[value] || value || 'Unknown';
    }

    function row(item) {
      const pending = item.status === 'pending';
      const busy = busyId === item.id;

      return `
        <tr>
          <td>
            <b>${escape(item.user_name || 'Team member')}</b>
            <div class="muted small">
              ${escape(item.user_email || '')}
            </div>
          </td>

          <td>${escape(item.start_date)}</td>
          <td>${escape(item.end_date)}</td>
          <td>${escape(item.qualifying_days ?? 0)}</td>

          <td style="min-width:180px">
            ${escape(item.reason)}
          </td>

          <td>${escape(statusLabel(item.status))}</td>
          <td>${escape(item.paid_leave_days ?? 0)}</td>
          <td>${escape(item.unpaid_leave_days ?? 0)}</td>

          <td style="min-width:220px">
            ${
              pending
                ? `
                  <input
                    type="text"
                    maxlength="2000"
                    placeholder="Optional review note"
                    data-leave-note="${escape(item.id)}"
                    ${busy ? 'disabled' : ''}
                  >

                  <div
                    style="
                      display:flex;
                      gap:8px;
                      margin-top:8px;
                      flex-wrap:wrap;
                    "
                  >
                    <button
                      type="button"
                      class="btn small"
                      data-leave-approve="${escape(item.id)}"
                      ${busy ? 'disabled' : ''}
                    >
                      ${busy ? 'Processing…' : 'Approve'}
                    </button>

                    <button
                      type="button"
                      class="btn-ghost small"
                      data-leave-reject="${escape(item.id)}"
                      ${busy ? 'disabled' : ''}
                    >
                      Reject
                    </button>
                  </div>
                `
                : escape(item.review_note || '—')
            }
          </td>
        </tr>
      `;
    }

    function paint() {
      if (!host) return;

      const rows = requests.map(row).join('');

      host.innerHTML = `
        <div class="section-head">
          <div>
            <h2>Leave Requests</h2>
            <p class="muted small">
              Review employee leave applications and paid leave allocation.
            </p>
          </div>

          <button
            type="button"
            class="btn-ghost small"
            data-admin-leave-refresh
            ${loading ? 'disabled' : ''}
          >
            Refresh
          </button>
        </div>

        <div class="admin-attendance-filters">
          <label>
            Status

            <select data-admin-leave-status ${loading ? 'disabled' : ''}>
              <option value="pending" ${status === 'pending' ? 'selected' : ''}>
                Pending
              </option>

              <option value="approved" ${status === 'approved' ? 'selected' : ''}>
                Approved
              </option>

              <option value="rejected" ${status === 'rejected' ? 'selected' : ''}>
                Rejected
              </option>

              <option value="" ${status === '' ? 'selected' : ''}>
                All
              </option>
            </select>
          </label>
        </div>

        ${
          error
            ? `<p class="attendance-error" role="alert">${escape(error)}</p>`
            : ''
        }

        <p
          class="attendance-feedback small"
          role="status"
          aria-live="polite"
        >
          ${escape(loading ? 'Loading leave requests…' : message)}
        </p>

        <div class="admin-attendance-table">
          <table>
            <caption class="muted small">
              Employee leave requests
            </caption>

            <thead>
              <tr>
                <th>Employee</th>
                <th>From</th>
                <th>To</th>
                <th>Working Days</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Paid</th>
                <th>Unpaid</th>
                <th>Review</th>
              </tr>
            </thead>

            <tbody>
              ${
                rows ||
                `
                  <tr>
                    <td colspan="9">
                      ${loading ? 'Loading…' : 'No leave requests found.'}
                    </td>
                  </tr>
                `
              }
            </tbody>
          </table>
        </div>
      `;

      host.setAttribute(
        'aria-busy',
        String(loading || busyId !== null)
      );

      host.onchange = event => {
        const select = event.target.closest('[data-admin-leave-status]');

        if (!select || !host.contains(select)) return;

        status = select.value;
        refresh();
      };

      host.onclick = event => {
        const refreshButton =
          event.target.closest('[data-admin-leave-refresh]');

        if (refreshButton && host.contains(refreshButton)) {
          refresh();
          return;
        }

        const approveButton =
          event.target.closest('[data-leave-approve]');

        if (approveButton && host.contains(approveButton)) {
          review(
            approveButton.dataset.leaveApprove,
            'approve'
          );
          return;
        }

        const rejectButton =
          event.target.closest('[data-leave-reject]');

        if (rejectButton && host.contains(rejectButton)) {
          review(
            rejectButton.dataset.leaveReject,
            'reject'
          );
        }
      };
    }

    async function read(token) {
      const suffix = status
        ? `?status=${encodeURIComponent(status)}`
        : '';

      const result = await request(
        `/api/admin/leave${suffix}`,
        'GET'
      );

      if (!active(token)) return;

      requests = Array.isArray(result.requests)
        ? result.requests
        : [];
    }

    async function refresh() {
      if (loading || busyId !== null || !active(generation)) return;

      const token = generation;

      loading = true;
      error = '';
      message = '';

      paint();

      try {
        await read(token);
      } catch (e) {
        if (active(token)) {
          error =
            e.message ||
            'Leave requests could not be loaded.';
        }
      } finally {
        if (active(token)) {
          loading = false;
          paint();
        }
      }
    }

    async function review(id, action) {
      if (
        loading ||
        busyId !== null ||
        !active(generation)
      ) {
        return;
      }

      const token = generation;

      const noteInput = host.querySelector(
        `[data-leave-note="${CSS.escape(id)}"]`
      );

      const note = noteInput?.value.trim() || null;

      busyId = id;
      error = '';
      message = '';

      paint();

      try {
        const result = await request(
          `/api/admin/leave/${encodeURIComponent(id)}/${action}`,
          'POST',
          { note }
        );

        if (!active(token)) return;

        message =
          result.message ||
          `Leave request ${action === 'approve' ? 'approved' : 'rejected'}.`;

        await read(token);
      } catch (e) {
        if (active(token)) {
          error =
            e.message ||
            'Leave request could not be reviewed.';
        }
      } finally {
        if (active(token)) {
          busyId = null;
          paint();
        }
      }
    }

    function reset() {
      generation++;

      if (host) {
        host.innerHTML = '';
        host.onclick = null;
        host.onchange = null;
      }

      host = null;
      owner = null;
      requests = [];
      status = 'pending';
      loading = false;
      busyId = null;
      error = '';
      message = '';
    }

    function mount(element) {
      const user = getUser();

      if (!element || user?.role !== 'admin') {
        reset();
        return;
      }

      if (owner !== user.id) {
        reset();
        owner = user.id;
      }

      host = element;
      paint();

      return refresh();
    }

    return {
      mount,
      refresh,
      reset,

      unmount() {
        if (host) {
          host.onclick = null;
          host.onchange = null;
        }

        host = null;
      }
    };
  }

  return { create };
});