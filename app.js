import { APP_CONFIG } from './config.js';
import { QueueManager } from './queueManager.js';

let elements = {};
let queueManager;
let paymentActivityRecordPage = 1;
let paymentActivityRecordRequestId = 0;
let paymentActivityRecordSearchTimer;
let paymentActivityRecordRefreshTimer;

async function readApiResponse(response, allowEmptyArray = false) {
  let result;
  try {
    result = await response.json();
  } catch {
    const error = new Error(`The server returned an invalid response (HTTP ${response.status}).`);
    error.status = response.status;
    throw error;
  }
  if (
    !result ||
    typeof result !== 'object' ||
    (Array.isArray(result) && !(allowEmptyArray && result.length === 0))
  ) {
    const error = new Error(`The server returned an invalid response (HTTP ${response.status}).`);
    error.status = response.status;
    throw error;
  }
  return result;
}

function createSafeElement(tag, className, textContent = '') {
  const el = document.createElement(tag);
  if (className) el.className = className;
  if (textContent) el.textContent = textContent;
  return el;
}

function getEnglishMessage(message, fallback = '') {
  const text = String(message || '').trim();
  const translations = {
    'Mangyaring ilagay ang Username at Password.': 'Please enter your username and password.',
    'Mali ang Username o Password.': 'The username or password is incorrect.',
    'Matagumpay na nakapag-log in!': 'Sign-in successful.'
  };
  return translations[text] || text || fallback;
}

function formatPaymentDetails(ticket) {
  if (ticket.service !== 'Tuition Payment') {
    return '';
  }

  const details = [
    ticket.payment,
    ticket.paymentPeriod
  ];
  return details.filter(Boolean).join(' / ');
}

function populateFormOptions() {
  elements.laneTypeSelect.innerHTML = '';
  APP_CONFIG.lanes.forEach(lane => {
    const opt = createSafeElement('option', '', lane.label);
    opt.value = lane.id;
    elements.laneTypeSelect.appendChild(opt);
  });

  elements.serviceTypeSelect.innerHTML = '';
  APP_CONFIG.services.forEach(srv => {
    const opt = createSafeElement('option', '', srv.label);
    opt.value = srv.label;
    elements.serviceTypeSelect.appendChild(opt);
  });

  elements.paymentTypeSelect.innerHTML = '';
  APP_CONFIG.paymentOptions.forEach(pmt => {
    const opt = createSafeElement('option', '', pmt.label);
    opt.value = pmt.label;
    elements.paymentTypeSelect.appendChild(opt);
  });

  elements.paymentPeriodSelect.innerHTML = '';
  APP_CONFIG.paymentPeriods.forEach(period => {
    const opt = createSafeElement('option', '', period.label);
    opt.value = period.id;
    elements.paymentPeriodSelect.appendChild(opt);
  });
}

function initClock() {
  const updateTime = () => {
    if (elements.liveClock) {
      elements.liveClock.textContent = new Date().toLocaleTimeString('en-US', {
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
      });
    }
  };
  updateTime();
  setInterval(updateTime, 1000);
}

function switchTab(targetPage, activeLink) {
  document.querySelectorAll('.page-view').forEach(page => page.classList.remove('active'));
  document.querySelectorAll('.nav-link').forEach(link => {
    link.classList.remove('active');
    link.removeAttribute('aria-current');
  });

  targetPage.classList.add('active');
  activeLink.classList.add('active');
  activeLink.setAttribute('aria-current', 'page');

  renderUI(queueManager.getState());
}

function renderUI(state) {
  renderQueuePage(state);
  elements.paymentActivityRecordActiveQueue.textContent =
    state.regularQueue.length + state.priorityQueue.length +
    Number(Boolean(state.currentRegular)) + Number(Boolean(state.currentPriority));
  renderReportsPage(state);
}

function renderQueuePage(state) {
  elements.kpiWaiting.textContent = state.regularQueue.length + state.priorityQueue.length;
  elements.kpiRegWait.textContent = state.regularQueue.length;
  elements.kpiPrioWait.textContent = state.priorityQueue.length;
  elements.kpiCompleted.textContent = state.counters.completed;
  elements.kpiSkipped.textContent = state.counters.skipped;

  const currentServing = state.currentPriority || state.currentRegular;
  elements.kpiServing.textContent = currentServing ? currentServing.id : '--';
  elements.kpiServingLane.textContent = currentServing ? `${currentServing.lane} Lane` : 'None';

  renderServingPanel(elements.regServingNum, elements.regServingDetails, state.currentRegular);
  updateControlButtons('Regular', state.currentRegular, state.regularQueue.length > 0);

  renderServingPanel(elements.prioServingNum, elements.prioServingDetails, state.currentPriority);
  updateControlButtons('Priority', state.currentPriority, state.priorityQueue.length > 0);

  renderTable(elements.regTableBody, state.regularQueue, 'No students in regular queue');
  renderTable(elements.prioTableBody, state.priorityQueue, 'No students in priority queue');
}

function renderServingPanel(numElem, detailsElem, currentItem) {
  numElem.textContent = currentItem ? currentItem.id : '--';
  detailsElem.innerHTML = '';

  if (!currentItem) {
    detailsElem.appendChild(createSafeElement('div', 'empty-msg', 'No active student'));
    return;
  }

  const fields = [
    { label: 'Student Name:', val: currentItem.studentName },
    { label: 'Service:', val: currentItem.service },
    { label: 'Time Issued:', val: currentItem.time }
  ];
  if (currentItem.service === 'Tuition Payment') {
    fields.splice(2, 0, { label: 'Payment:', val: formatPaymentDetails(currentItem) });
  }

  fields.forEach(field => {
    const row = createSafeElement('div', 'details-row');
    row.appendChild(createSafeElement('span', 'details-label', field.label));
    row.appendChild(createSafeElement('span', 'details-value', field.val));
    detailsElem.appendChild(row);
  });
}

function updateControlButtons(lane, currentItem, hasWaiting) {
  const isReg = lane === 'Regular';
  const btnNext = isReg ? elements.btnRegNext : elements.btnPrioNext;
  const btnSkip = isReg ? elements.btnRegSkip : elements.btnPrioSkip;
  const btnRecall = isReg ? elements.btnRegRecall : elements.btnPrioRecall;
  const btnComplete = isReg ? elements.btnRegComplete : elements.btnPrioComplete;

  btnNext.disabled = !!currentItem || !hasWaiting;
  btnSkip.disabled = !currentItem;
  btnRecall.disabled = !currentItem || currentItem.recallCount >= 3;
  btnRecall.title = currentItem
    ? `Recall ${currentItem.recallCount} of 3 used`
    : 'Recall unavailable';
  btnComplete.disabled = !currentItem;
}

function renderTable(tbody, queue, emptyText) {
  tbody.innerHTML = '';

  if (queue.length === 0) {
    const tr = createSafeElement('tr');
    const td = createSafeElement('td', 'empty-msg', emptyText);
    td.colSpan = 6;
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  queue.forEach((item, index) => {
    const tr = createSafeElement('tr');
    const tdId = createSafeElement('td');
    tdId.appendChild(createSafeElement('strong', '', item.id));

    tr.appendChild(tdId);
    tr.appendChild(createSafeElement('td', '', item.studentName));
    tr.appendChild(createSafeElement('td', '', item.service));
    tr.appendChild(createSafeElement('td', '', formatPaymentDetails(item)));
    tr.appendChild(createSafeElement('td', '', item.time));

    const tdStatus = createSafeElement('td');
    const badgeClass = index === 0 ? 'badge-status status-next' : 'badge-status status-waiting';
    const badgeText = index === 0 ? 'Next' : 'Waiting';
    tdStatus.appendChild(createSafeElement('span', badgeClass, badgeText));
    tr.appendChild(tdStatus);

    tbody.appendChild(tr);
  });
}

function renderPaymentActivity(activity) {
  const chart = elements.paymentActivityChart;
  chart.replaceChildren();
  const days = activity.slice(-7);
  const peak = Math.max(...days.map(day => Number(day.records) || 0), 1);
  const total = days.reduce((sum, day) => sum + (Number(day.records) || 0), 0);
  elements.paymentActivitySummary.textContent = `${total.toLocaleString('en-PH')} records`;

  if (!days.length) {
    chart.appendChild(createSafeElement('p', 'payment-chart-empty', 'No payment activity in the last seven days.'));
  }

  days.forEach(day => {
    const column = createSafeElement('div', 'payment-chart-column');
    const records = Number(day.records) || 0;
    const bar = createSafeElement('div', 'payment-chart-bar');
    bar.style.height = `${Math.max(records > 0 ? 8 : 2, records / peak * 100)}%`;
    bar.title = `${day.activity_date}: ${records} records`;
    bar.setAttribute('aria-label', `${day.activity_date}: ${records} payment activity records`);
    const label = createSafeElement('span', 'payment-chart-label', String(day.activity_date).slice(5));
    column.append(bar, label);
    chart.appendChild(column);
  });

}

function renderPaymentActivityRecordRows(records) {
  const tbody = elements.txTableBody;
  tbody.replaceChildren();
  if (!records.length) {
    const row = createSafeElement('tr');
    row.appendChild(createSafeElement('td', 'empty-msg', 'No payment activity records match these filters.'));
    row.firstElementChild.colSpan = 8;
    tbody.appendChild(row);
    return;
  }

  records.forEach(record => {
    const row = createSafeElement('tr');
    const getValue = (...keys) => {
      for (const key of keys) {
        const value = record[key];
        if (value !== undefined && value !== null && String(value).trim() !== '') {
          return String(value).trim();
        }
      }
      return '';
    };
    const courseYear = getValue('course_year', 'course_year_level') ||
      [getValue('course'), getValue('year_level')].filter(Boolean).join(' / ') || '—';
    const rawCreatedAt = getValue('created_at', 'date_time', 'datetime', 'transaction_date');
    const createdAt = new Date(rawCreatedAt);
    const formattedDate = Number.isNaN(createdAt.getTime())
      ? rawCreatedAt || '—'
      : createdAt.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
    const paymentType = getValue('payment_type', 'payment_description', 'payment_method', 'transaction_type');
    const amount = getValue('amount', 'amount_paid');
    const paymentDisplay = [paymentType, amount ? `₱${amount}` : ''].filter(Boolean).join(' · ');
    const fields = [
      getValue('transaction_ref', 'transaction_reference', 'reference_number'),
      getValue('student_id'),
      getValue('student_name'),
      courseYear,
      paymentDisplay,
      formattedDate,
      getValue('cashier_staff', 'cashier')
    ];
    fields.forEach(value => row.appendChild(createSafeElement('td', '', value || '—')));

    const statusCell = createSafeElement('td');
    const status = getValue('status') || 'recorded';
    const isRecorded = status.toLowerCase() === 'recorded';
    statusCell.appendChild(createSafeElement(
      'span',
      `badge-status ${isRecorded ? 'status-completed' : 'status-waiting'}`,
      isRecorded ? 'Recorded' : status
    ));
    row.appendChild(statusCell);
    tbody.appendChild(row);
  });
}

function updatePaymentActivityStatusOptions(statuses) {
  const selectedStatus = elements.txFilterStatus.value;
  const options = [createSafeElement('option', '', 'All statuses')];
  options[0].value = 'all';

  statuses.forEach(status => {
    const option = createSafeElement('option', '', status.charAt(0).toUpperCase() + status.slice(1));
    option.value = status;
    options.push(option);
  });

  elements.txFilterStatus.replaceChildren(...options);
  elements.txFilterStatus.value = statuses.includes(selectedStatus) ? selectedStatus : 'all';
}

async function fetchPaymentActivityRecords() {
  const requestId = ++paymentActivityRecordRequestId;
  const params = new URLSearchParams({
    action: 'get_cashier_payment_activity_records',
    search: elements.txSearchInput.value.trim(),
    range: elements.txFilterRange.value,
    status: elements.txFilterStatus.value,
    page: String(paymentActivityRecordPage)
  });
  elements.paymentActivityRecordMessage.textContent = 'Loading payment activity records…';

  try {
    const response = await fetch(`${APP_CONFIG.apiUrl}?${params}`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await readApiResponse(response, true);
    if (!response.ok || (!Array.isArray(result) && (!result.success || !Array.isArray(result.data)))) {
      throw new Error(result.message || 'Unable to load payment activity records.');
    }
    if (requestId !== paymentActivityRecordRequestId) return;

    const payload = Array.isArray(result)
      ? {
          configured: true,
          success: true,
          data: result,
          pagination: { page: 1, pages: 1, total: result.length },
          metrics: { total_records: result.length, today_records: 0, active_queue_count: 0 },
          statuses: [],
          activity: []
        }
      : result;
    const records = payload.data
      .filter(record => record && typeof record === 'object')
      .map(record => ({
        ...record,
        transaction_ref: record.transaction_ref ?? record.transaction_reference ?? record.reference_number ?? '',
        course_year: record.course_year ?? record.course_year_level ?? '',
        payment_type: record.payment_type ?? record.payment_description ?? record.payment_method ?? record.transaction_type ?? '',
        created_at: record.created_at ?? record.date_time ?? record.datetime ?? record.transaction_date ?? '',
        amount: record.amount ?? record.amount_paid ?? ''
      }));
    const metrics = payload.metrics || {};
    const paginationResult = payload.pagination || {};
    const pagination = {
      page: Number(paginationResult.page) || 1,
      pages: Math.max(1, Number(paginationResult.pages) || 1),
      total: Math.max(Number(paginationResult.total) || 0, records.length)
    };

    const statuses = Array.isArray(payload.statuses) && payload.statuses.length
      ? payload.statuses
      : [...new Set(records.map(record => String(record.status || 'recorded').toLowerCase()))];
    updatePaymentActivityStatusOptions(statuses);
    elements.paymentActivityRecordTotalRecords.textContent =
      Number(metrics.total_records ?? payload.total_records ?? 0).toLocaleString('en-PH');
    elements.paymentActivityRecordTodayRecords.textContent =
      Number(metrics.today_records ?? payload.today_records ?? 0).toLocaleString('en-PH');
    elements.paymentActivityRecordActiveQueue.textContent =
      Number(metrics.active_queue_count ?? 0).toLocaleString('en-PH');
    renderPaymentActivity(Array.isArray(payload.activity) ? payload.activity : []);
    renderPaymentActivityRecordRows(records);

    paymentActivityRecordPage = pagination.page;
    elements.paymentActivityRecordPageInfo.textContent =
      `Page ${pagination.page} of ${pagination.pages} · ${Number(pagination.total || 0).toLocaleString('en-PH')} records`;
    elements.paymentActivityRecordPrevious.disabled = pagination.page <= 1;
    elements.paymentActivityRecordNext.disabled = pagination.page >= pagination.pages;
    elements.paymentActivityRecordMessage.textContent =
      Number(pagination.total || 0) === 0 ? 'No records found.' : `${Number(pagination.total).toLocaleString('en-PH')} matching records`;
  } catch (error) {
    if (requestId !== paymentActivityRecordRequestId) return;
    console.error('Failed to load payment activity records:', error);
    const errorMessage =
      getEnglishMessage(error.message, 'Unable to load payment activity records.');
    elements.paymentActivityRecordMessage.textContent = errorMessage;
    renderPaymentActivityRecordRows([]);
    const errorCell = elements.txTableBody.querySelector('.empty-msg');
    if (errorCell) errorCell.textContent = errorMessage;
  }
}

function renderReportsPage(state) {
  const totalTickets = state.counters.regSequence + state.counters.prioSequence;
  const totalEnded = state.counters.completed + state.counters.skipped;
  const rate = totalEnded > 0 ? Math.round((state.counters.completed / totalEnded) * 100) : 0;

  elements.rptTotalTickets.textContent = totalTickets;
  elements.rptCompletionRate.textContent = `${rate}%`;
  elements.rptRatio.textContent = `${state.counters.regSequence} Reg / ${state.counters.prioSequence} Prio`;

  const serviceCounts = {};
  APP_CONFIG.services.forEach(s => serviceCounts[s.label] = 0);

  const paymentCounts = {};
  APP_CONFIG.paymentOptions.forEach(p => paymentCounts[p.label] = 0);
  let visiblePaymentCount = 0;

  const allItems = [
    ...state.transactions,
    ...state.regularQueue,
    ...state.priorityQueue,
    ...(state.currentRegular ? [state.currentRegular] : []),
    ...(state.currentPriority ? [state.currentPriority] : [])
  ];

  allItems.forEach(item => {
    if (item.service) serviceCounts[item.service] = (serviceCounts[item.service] || 0) + 1;
    if (item.service === 'Tuition Payment' && item.payment) {
      paymentCounts[item.payment] = (paymentCounts[item.payment] || 0) + 1;
      visiblePaymentCount++;
    }
  });

  renderDistributionTable(elements.rptServicesBody, serviceCounts, allItems.length);
  elements.paymentSummary.hidden = visiblePaymentCount === 0;
  renderDistributionTable(elements.rptPaymentsBody, paymentCounts, visiblePaymentCount);
}

function renderDistributionTable(tbody, dataMap, total) {
  tbody.innerHTML = '';
  Object.keys(dataMap).forEach(key => {
    const count = dataMap[key];
    const pct = total > 0 ? Math.round((count / total) * 100) : 0;

    const tr = createSafeElement('tr');
    tr.appendChild(createSafeElement('td', '', key));
    tr.appendChild(createSafeElement('td', '', count));
    tr.appendChild(createSafeElement('td', '', `${pct}%`));
    tbody.appendChild(tr);
  });
}

function bindEvents() {
  const returnToPortal = () => window.location.assign('index.html');

  elements.cashierLoginCancelBtn.addEventListener('click', returnToPortal);
  elements.cashierLoginDialog.addEventListener('cancel', event => {
    event.preventDefault();
    returnToPortal();
  });

  elements.cashierLoginForm.addEventListener('submit', async event => {
    event.preventDefault();
    elements.cashierLoginMessage.textContent = '';
    const submitButton = elements.cashierLoginForm.querySelector('[type="submit"]');
    submitButton.disabled = true;
    try {
      const response = await fetch(APP_CONFIG.apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'cashier_login',
          username: elements.cashierLoginUsername.value.trim(),
          password: elements.cashierLoginPassword.value
        })
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success || !result.csrf_token) {
        throw new Error(result.message || 'Cashier sign-in failed.');
      }
      elements.cashierLoginForm.reset();
      await startCashierSession(result.csrf_token);
    } catch (error) {
      elements.cashierLoginMessage.textContent =
        getEnglishMessage(error.message, 'Cashier sign-in failed.');
    } finally {
      submitButton.disabled = false;
    }
  });

  elements.cashierLogoutBtn.addEventListener('click', async () => {
    try {
      const response = await fetch(APP_CONFIG.apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': queueManager.csrfToken },
        body: JSON.stringify({ action: 'logout' })
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Unable to sign out.');
      }
      window.location.replace('student.html');
    } catch (error) {
      alert(getEnglishMessage(error.message, 'Unable to sign out.'));
    }
  });

  elements.navQueue.addEventListener('click', (e) => {
    e.preventDefault();
    switchTab(elements.pageQueue, elements.navQueue);
  });

  elements.navPaymentActivityRecord.addEventListener('click', (e) => {
    e.preventDefault();
    switchTab(elements.pagePaymentActivityRecord, elements.navPaymentActivityRecord);
    fetchPaymentActivityRecords();
  });

  elements.navReports.addEventListener('click', (e) => {
    e.preventDefault();
    switchTab(elements.pageReports, elements.navReports);
  });

  elements.txSearchInput.addEventListener('input', () => {
    clearTimeout(paymentActivityRecordSearchTimer);
    paymentActivityRecordSearchTimer = setTimeout(() => {
      paymentActivityRecordPage = 1;
      fetchPaymentActivityRecords();
    }, 250);
  });
  [elements.txFilterRange, elements.txFilterStatus].forEach(filter => {
    filter.addEventListener('change', () => {
      paymentActivityRecordPage = 1;
      fetchPaymentActivityRecords();
    });
  });
  elements.paymentActivityRecordPrevious.addEventListener('click', () => {
    paymentActivityRecordPage = Math.max(1, paymentActivityRecordPage - 1);
    fetchPaymentActivityRecords();
  });
  elements.paymentActivityRecordNext.addEventListener('click', () => {
    paymentActivityRecordPage++;
    fetchPaymentActivityRecords();
  });
  elements.openModalBtn.addEventListener('click', () => elements.dialog.showModal());
  elements.serviceTypeSelect.addEventListener('change', updateTuitionFields);
  const closeModal = () => elements.dialog.close();
  elements.closeModalBtn.addEventListener('click', closeModal);
  elements.closeModalCross.addEventListener('click', closeModal);

  elements.ticketForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const studentName = elements.studentNameInput.value.trim().replace(/\s+/g, ' ');

    const studentId = elements.studentIdInput.value.trim();
    if (!studentName || !studentId) {
      alert('Enter both the Student ID and full name shown in the active CCDI student record.');
      return;
    }

    const isTuitionPayment = elements.serviceTypeSelect.value === 'Tuition Payment';
    const created = await queueManager.createTicket({
      lane: elements.laneTypeSelect.value,
      studentId,
      studentName: studentName,
      service: elements.serviceTypeSelect.value,
      payment: isTuitionPayment ? elements.paymentTypeSelect.value : 'N/A (Document Only)',
      paymentPeriod: isTuitionPayment ? elements.paymentPeriodSelect.value : ''
    });
    if (!created) return;
    elements.ticketForm.reset();
    elements.dialog.close();
  });

  elements.btnRegNext.addEventListener('click', async () => await queueManager.callNext('Regular'));
  elements.btnRegComplete.addEventListener('click', async () => {
    await queueManager.completeCurrent('Regular');
    await fetchPaymentActivityRecords();
  });
  elements.btnRegSkip.addEventListener('click', async () => await queueManager.skipCurrent('Regular'));
  elements.btnRegRecall.addEventListener('click', async () => {
    const active = queueManager.getState().currentRegular;
    if (active) {
      const res = await queueManager.recallCurrent('Regular');
      if (res) {
        const attempt = res.data?.recall_count || (active.recallCount + 1);
        alert(`Your ticket is being called at the cashier. Please return to the queue (attempt ${attempt} of 3).\nTicket: ${active.id}`);
      }
    }
  });

  elements.btnPrioNext.addEventListener('click', async () => await queueManager.callNext('Priority'));
  elements.btnPrioComplete.addEventListener('click', async () => {
    await queueManager.completeCurrent('Priority');
    await fetchPaymentActivityRecords();
  });
  elements.btnPrioSkip.addEventListener('click', async () => await queueManager.skipCurrent('Priority'));
  elements.btnPrioRecall.addEventListener('click', async () => {
    const active = queueManager.getState().currentPriority;
    if (active) {
      const res = await queueManager.recallCurrent('Priority');
      if (res) {
        const attempt = res.data?.recall_count || (active.recallCount + 1);
        alert(`Your ticket is being called at the cashier. Please return to the queue (attempt ${attempt} of 3).\nTicket: ${active.id}`);
      }
    }
  });

  elements.resetSystemBtn.addEventListener('click', async () => {
    if (!window.confirm("Are you sure you want to reset the queue? This will archive today's queue records.")) {
      return;
    }
    try {
      await queueManager.resetDay();
      await fetchPaymentActivityRecords();
      alert('Operational day reset. The daily report was archived successfully.');
    } catch (error) {
      alert(getEnglishMessage(error.message, 'Unable to reset the operational day.'));
    }
  });
}

function init() {
  elements = {
    navQueue: document.getElementById('navQueue'),
    navPaymentActivityRecord: document.getElementById('navPaymentActivityRecord'),
    navReports: document.getElementById('navReports'),

    pageQueue: document.getElementById('pageQueue'),
    pagePaymentActivityRecord: document.getElementById('pagePaymentActivityRecord'),
    pageReports: document.getElementById('pageReports'),

    txSearchInput: document.getElementById('txSearchInput'),
    txFilterStatus: document.getElementById('txFilterStatus'),
    txFilterRange: document.getElementById('txFilterRange'),
    txTableBody: document.getElementById('txTableBody'),
    paymentActivityRecordTotalRecords: document.getElementById('paymentActivityRecordTotalRecords'),
    paymentActivityRecordTodayRecords: document.getElementById('paymentActivityRecordTodayRecords'),
    paymentActivityRecordActiveQueue: document.getElementById('paymentActivityRecordActiveQueue'),
    paymentActivityChart: document.getElementById('paymentActivityChart'),
    paymentActivitySummary: document.getElementById('paymentActivitySummary'),
    paymentActivityRecordMessage: document.getElementById('paymentActivityRecordMessage'),
    paymentActivityRecordPageInfo: document.getElementById('paymentActivityRecordPageInfo'),
    paymentActivityRecordPrevious: document.getElementById('paymentActivityRecordPrevious'),
    paymentActivityRecordNext: document.getElementById('paymentActivityRecordNext'),
    paymentSummary: document.getElementById('paymentSummary'),

    rptTotalTickets: document.getElementById('rptTotalTickets'),
    rptCompletionRate: document.getElementById('rptCompletionRate'),
    rptRatio: document.getElementById('rptRatio'),
    rptServicesBody: document.getElementById('rptServicesBody'),
    rptPaymentsBody: document.getElementById('rptPaymentsBody'),

    dialog: document.getElementById('ticketDialog'),
    openModalBtn: document.getElementById('openModalBtn'),
    closeModalBtn: document.getElementById('closeModalBtn'),
    closeModalCross: document.getElementById('closeModalCross'),
    ticketForm: document.getElementById('ticketForm'),
    laneTypeSelect: document.getElementById('laneType'),
    serviceTypeSelect: document.getElementById('serviceType'),
    paymentTypeSelect: document.getElementById('paymentType'),
    paymentPeriodSelect: document.getElementById('paymentPeriod'),

    studentNameInput: document.getElementById('studentName'),
    studentIdInput: document.getElementById('studentId'),
    tuitionPaymentFields: document.getElementById('tuitionPaymentFields'),

    cashierLoginDialog: document.getElementById('cashierLoginDialog'),
    cashierLoginForm: document.getElementById('cashierLoginForm'),
    cashierLoginCancelBtn: document.getElementById('cashierLoginCancelBtn'),
    cashierLoginUsername: document.getElementById('cashierLoginUsername'),
    cashierLoginPassword: document.getElementById('cashierLoginPassword'),
    cashierLoginMessage: document.getElementById('cashierLoginMessage'),
    cashierLogoutBtn: document.getElementById('cashierLogoutBtn'),

    liveClock: document.getElementById('liveClock'),
    resetSystemBtn: document.getElementById('resetSystemBtn'),

    kpiWaiting: document.getElementById('kpiWaiting'),
    kpiRegWait: document.getElementById('kpiRegWait'),
    kpiPrioWait: document.getElementById('kpiPrioWait'),
    kpiServing: document.getElementById('kpiServing'),
    kpiServingLane: document.getElementById('kpiServingLane'),
    kpiCompleted: document.getElementById('kpiCompleted'),
    kpiSkipped: document.getElementById('kpiSkipped'),

    btnRegNext: document.getElementById('btnRegNext'),
    btnRegSkip: document.getElementById('btnRegSkip'),
    btnRegRecall: document.getElementById('btnRegRecall'),
    btnRegComplete: document.getElementById('btnRegComplete'),

    btnPrioNext: document.getElementById('btnPrioNext'),
    btnPrioSkip: document.getElementById('btnPrioSkip'),
    btnPrioRecall: document.getElementById('btnPrioRecall'),
    btnPrioComplete: document.getElementById('btnPrioComplete'),

    regServingNum: document.getElementById('regServingNum'),
    regServingDetails: document.getElementById('regServingDetails'),
    regTableBody: document.getElementById('regTableBody'),

    prioServingNum: document.getElementById('prioServingNum'),
    prioServingDetails: document.getElementById('prioServingDetails'),
    prioTableBody: document.getElementById('prioTableBody')
  };

  populateFormOptions();
  updateTuitionFields();
  initClock();
  bindEvents();
  restoreCashierSession();
}

function updateTuitionFields() {
  const isTuition = elements.serviceTypeSelect.value === 'Tuition Payment';
  elements.tuitionPaymentFields.hidden = !isTuition;
  elements.paymentTypeSelect.required = isTuition;
  if (!isTuition) {
    elements.paymentTypeSelect.value = 'N/A (Document Only)';
    elements.paymentPeriodSelect.value = '';
  }
}

async function restoreCashierSession() {
  try {
    const response = await fetch(`${APP_CONFIG.apiUrl}?action=auth_status`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await readApiResponse(response);
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'Unable to verify cashier session.');
    }
    if (result.authenticated && result.role === 'cashier' && result.csrf_token) {
      await startCashierSession(result.csrf_token);
      return;
    }
    elements.cashierLoginDialog.showModal();
  } catch (error) {
    elements.cashierLoginMessage.textContent =
      getEnglishMessage(error.message, 'Unable to verify cashier session.');
    elements.cashierLoginDialog.showModal();
  }
}

async function startCashierSession(csrfToken) {
  document.body.classList.remove('auth-locked');
  if (elements.cashierLoginDialog.open) elements.cashierLoginDialog.close();
  queueManager = new QueueManager(csrfToken);
  queueManager.subscribe(renderUI);
  queueManager.subscribeToSyncStatus(status => {
    const statusElement = document.getElementById('storageStatus');
    if (statusElement) statusElement.textContent = status;
  });
  paymentActivityRecordPage = 1;
  fetchPaymentActivityRecords();
  clearInterval(paymentActivityRecordRefreshTimer);
  paymentActivityRecordRefreshTimer = setInterval(fetchPaymentActivityRecords, 30000);
}

document.addEventListener('DOMContentLoaded', init);