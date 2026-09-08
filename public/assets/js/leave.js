(function (root, factory) {
  const api = factory();

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.LeaveCard = api;
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
    let balance = null;

    let busy = false;
    let loading = false;
    let error = '';
    let message = '';

    const active = token =>
      token === generation &&
      getUser()?.role === 'team' &&
      getUser()?.id === owner;

    function statusLabel(status) {
      return {
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected'
      }[status] || status || 'Unknown';
    }

    function paint() {
      if (!host) return;

      const disabled = busy || loading ? ' disabled' : '';

      const rows = requests.map(item => `
        <tr>
          <td>${escape(item.start_date)}</td>
          <td>${escape(item.end_date)}</td>
          <td>${escape(item.reason)}</td>
          <td>${escape(statusLabel(item.status))}</td>
          <td>${escape(item.qualifying_days ?? 0)}</td>
          <td>${escape(item.paid_leave_days ?? 0)}</td>
          <td>${escape(item.unpaid_leave_days ?? 0)}</td>
          <td>${escape(item.review_note || '—')}</td>
        </tr>
      `).join('');

      host.innerHTML = `
        <div class="section-head">
          <div>
            <h2>Leave</h2>
            <p class="muted small">
              Apply for leave and view your annual leave balance.
            </p>
          </div>

          <button
            type="button"
            class="btn-ghost small"
            data-leave-refresh
            ${disabled}
          >
            Refresh
          </button>
        </div>

        <div class="leave-balance">
          <div>
            <strong>${escape(balance?.entitlement ?? '—')}</strong>
            <span>Annual Paid Leave</span>
          </div>

          <div>
            <strong>${escape(balance?.paid_used ?? '—')}</strong>
            <span>Paid Used</span>
          </div>

          <div>
            <strong>${escape(balance?.paid_remaining ?? '—')}</strong>
            <span>Paid Remaining</span>
          </div>

          <div>
            <strong>${escape(balance?.unpaid_used ?? '—')}</strong>
            <span>Unpaid Used</span>
          </div>
        </div>

        <form data-leave-form class="leave-form">
          <label>
            From
            <input
              type="date"
              name="start_date"
              required
              ${disabled}
            >
          </label>

          <label>
            To
            <input
              type="date"
              name="end_date"
              required
              ${disabled}
            >
          </label>

          <label class="leave-reason">
            Reason
            <textarea
              name="reason"
              maxlength="2000"
              required
              placeholder="Reason for leave"
              ${disabled}
            ></textarea>
          </label>

          <button type="submit" class="btn" ${disabled}>
            Apply for Leave
          </button>
        </form>

        ${error
          ? `<p class="attendance-error" role="alert">${escape(error)}</p>`
          : ''
        }

        <p
          class="attendance-feedback small"
          role="status"
          aria-live="polite"
        >
          ${escape(loading ? 'Loading leave information…' : message)}
        </p>

        <div class="admin-attendance-table">
          <table>
            <caption class="muted small">
              Your leave requests
            </caption>

            <thead>
              <tr>
                <th>From</th>
                <th>To</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Working Days</th>
                <th>Paid</th>
                <th>Unpaid</th>
                <th>Admin Note</th>
              </tr>
            </thead>

            <tbody>
              ${rows || `
                <tr>
                  <td colspan="8">
                    ${loading
                      ? 'Loading…'
                      : 'No leave requests found.'
                    }
                  </td>
                </tr>
              `}
            </tbody>
          </table>
        </div>
      `;

      host.setAttribute(
        'aria-busy',
        String(busy || loading)
      );

      host.onclick = event => {
        const button = event.target.closest('[data-leave-refresh]');

        if (button && host.contains(button)) {
          refresh();
        }
      };

      host.onsubmit = event => {
        const form = event.target.closest('[data-leave-form]');

        if (!form || !host.contains(form)) return;

        event.preventDefault();

        const fields = form.elements;

        submit(
          fields.start_date.value,
          fields.end_date.value,
          fields.reason.value
        );
      };
    }

    async function read(token) {
      const [requestData, balanceData] = await Promise.all([
        request('/api/leave/mine', 'GET'),
        request('/api/leave/balance', 'GET')
      ]);

      if (!active(token)) return;

      requests = Array.isArray(requestData.requests)
        ? requestData.requests
        : [];

      balance = balanceData;
    }

    async function refresh() {
      if (busy || loading || !active(generation)) return;

      const token = generation;

      loading = true;
      error = '';
      message = '';

      paint();

      try {
        await read(token);
      } catch (e) {
        if (active(token)) {
          error = e.message || 'Leave information could not be loaded.';
        }
      } finally {
        if (active(token)) {
          loading = false;
          paint();
        }
      }
    }

    async function submit(startDate, endDate, reason) {
      if (busy || loading || !active(generation)) return;

      const token = generation;

      busy = true;
      error = '';
      message = '';

      paint();

      try {
        const result = await request('/api/leave', 'POST', {
          start_date: startDate,
          end_date: endDate,
          reason
        });

        if (!active(token)) return;

        message =
          result.message ||
          'Leave request submitted successfully.';

        await read(token);
      } catch (e) {
        if (active(token)) {
          error =
            e.message ||
            'Leave request could not be submitted.';
        }
      } finally {
        if (active(token)) {
          busy = false;
          paint();
        }
      }
    }

    function reset() {
      generation++;

      if (host) {
        host.innerHTML = '';
        host.onclick = null;
        host.onsubmit = null;
      }

      host = null;
      owner = null;
      requests = [];
      balance = null;
      busy = false;
      loading = false;
      error = '';
      message = '';
    }

    function mount(element) {
      const user = getUser();

      if (!element || user?.role !== 'team') {
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
      submit,
      reset,
      unmount() {
        if (host) {
          host.onclick = null;
          host.onsubmit = null;
        }

        host = null;
      }
    };
  }

  return { create };
});