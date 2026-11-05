import { APP_CONFIG } from './config.js';
import { QueueManager } from './queueManager.js';

let elements = {};
let queueManager;
let paymentHistoryPage = 1;
let paymentHistoryRequestId = 0;
let paymentHistorySearchTimer;
let paymentHistoryRefreshTimer;

function createSafeElement(tag, className, textContent = '') {
  const el = document.createElement(tag);
  if (className) el.className = className;
  if (textContent) el.textContent = textContent;
  return el;
}

function formatPaymentDetails(ticket) {
  if (ticket.service !== 'Tuition Payment') {
    return '';
  }

  const details = [
    ticket.payment,
    ticket.paymentPeriod,
    ticket.paymentPercentage,
    ticket.discount && ticket.discount !== 'None' ? `Discount: ${ticket.discount}` : ''
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
  document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));

  targetPage.classList.add('active');
  activeLink.classList.add('active');

  renderUI(queueManager.getState());
}

function renderUI(state) {
  renderQueuePage(state);
  elements.historyActiveQueue.textContent =
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

function formatCurrency(value) {
  return Number(value || 0).toLocaleString('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function renderSparkline(container, values) {
  container.replaceChildren();
  if (!values.length) return;

  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 100 32');
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.setAttribute('focusable', 'false');
  const max = Math.max(...values, 1);
  const min = Math.min(...values, 0);
  const spread = max - min || 1;
  const points = values.map((value, index) => {
    const x = values.length === 1 ? 50 : index * 100 / (values.length - 1);
    const y = 28 - ((value - min) / spread) * 24;
    return `${x},${y}`;
  }).join(' ');
  const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
  line.setAttribute('points', points);
  line.setAttribute('fill', 'none');
  line.setAttribute('stroke', 'currentColor');
  line.setAttribute('stroke-width', '2');
  line.setAttribute('vector-effect', 'non-scaling-stroke');
  svg.appendChild(line);
  container.appendChild(svg);
}

function renderPaymentActivity(activity) {
  const chart = elements.paymentActivityChart;
  chart.replaceChildren();
  const days = activity.slice(-7);
  const peak = Math.max(...days.map(day => Number(day.revenue) || 0), 1);
  const total = days.reduce((sum, day) => sum + (Number(day.revenue) || 0), 0);
  elements.paymentActivitySummary.textContent = formatCurrency(total);

  if (!days.length) {
    chart.appendChild(createSafeElement('p', 'payment-chart-empty', 'No payment activity in the last seven days.'));
  }

  days.forEach(day => {
    const column = createSafeElement('div', 'payment-chart-column');
    const amount = Number(day.revenue) || 0;
    const bar = createSafeElement('div', 'payment-chart-bar');
    bar.style.height = `${Math.max(amount > 0 ? 8 : 2, amount / peak * 100)}%`;
    bar.title = `${day.activity_date}: ${formatCurrency(amount)}`;
    bar.setAttribute('aria-label', `${day.activity_date}: ${formatCurrency(amount)}, ${day.transactions} transactions`);
    const label = createSafeElement('span', 'payment-chart-label', String(day.activity_date).slice(5));
    column.append(bar, label);
    chart.appendChild(column);
  });

  renderSparkline(elements.paymentCollectionsSpark, days.map(day => Number(day.revenue) || 0));
  renderSparkline(elements.paymentDailySpark, days.map(day => Number(day.revenue) || 0));
  renderSparkline(elements.paymentTransactionsSpark, days.map(day => Number(day.transactions) || 0));
}

function renderPaymentHistoryRows(records) {
  const tbody = elements.txTableBody;
  tbody.replaceChildren();
  if (!records.length) {
    const row = createSafeElement('tr');
    row.appendChild(createSafeElement('td', 'empty-msg', 'No payment history matches these filters.'));
    row.firstElementChild.colSpan = 9;
    tbody.appendChild(row);
    return;
  }

  records.forEach(record => {
    const row = createSafeElement('tr');
    const courseYear = [record.course, record.year_level].filter(Boolean).join(' / ') || '—';
    const createdAt = new Date(record.created_at);
    const formattedDate = Number.isNaN(createdAt.getTime())
      ? record.created_at
      : createdAt.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
    const fields = [
      record.reference_number,
      record.student_id,
      record.student_name,
      courseYear,
      formatCurrency(record.amount_paid),
      record.payment_method,
      formattedDate,
      record.cashier_staff
    ];
    fields.forEach(value => row.appendChild(createSafeElement('td', '', value || '—')));

    const statusCell = createSafeElement('td');
    const isPaid = record.status === 'paid';
    statusCell.appendChild(createSafeElement(
      'span',
      `badge-status ${isPaid ? 'status-completed' : 'status-pending'}`,
      isPaid ? 'Paid' : 'Pending'
    ));
    row.appendChild(statusCell);
    tbody.appendChild(row);
  });
}

async function fetchPaymentHistories({ exportAll = false } = {}) {
  const requestId = ++paymentHistoryRequestId;
  const params = new URLSearchParams({
    action: 'get_cashier_payment_histories',
    search: elements.txSearchInput.value.trim(),
    range: elements.txFilterRange.value,
    status: elements.txFilterStatus.value,
    page: String(paymentHistoryPage)
  });
  if (exportAll) params.set('all', '1');
  if (!exportAll) elements.paymentHistoryMessage.textContent = 'Loading payment history…';

  try {
    const response = await fetch(`${APP_CONFIG.apiUrl}?${params}`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await response.json();
    if (!response.ok || !result.success || !Array.isArray(result.data)) {
      throw new Error(result.message || 'Unable to load payment histories.');
    }

    if (exportAll) return result.data;
    if (requestId !== paymentHistoryRequestId) return [];

    elements.historyTotalCollections.textContent = formatCurrency(result.metrics.total_collections);
    elements.historyTotalTransactions.textContent =
      Number(result.metrics.total_transactions).toLocaleString('en-PH');
    elements.historyDailyRevenue.textContent = formatCurrency(result.metrics.daily_revenue);
    elements.historyActiveQueue.textContent =
      Number(result.metrics.active_queue_count).toLocaleString('en-PH');
    renderPaymentActivity(result.activity);
    renderPaymentHistoryRows(result.data);

    const pagination = result.pagination;
    paymentHistoryPage = pagination.page;
    elements.paymentHistoryPageInfo.textContent =
      `Page ${pagination.page} of ${pagination.pages} · ${pagination.total.toLocaleString('en-PH')} records`;
    elements.paymentHistoryPrevious.disabled = pagination.page <= 1;
    elements.paymentHistoryNext.disabled = pagination.page >= pagination.pages;
    elements.paymentHistoryMessage.textContent =
      pagination.total === 0 ? 'No records found.' : `${pagination.total.toLocaleString('en-PH')} matching records`;
    return result.data;
  } catch (error) {
    if (exportAll) throw error;
    if (requestId !== paymentHistoryRequestId) return [];
    console.error('Failed to load payment histories:', error);
    elements.paymentHistoryMessage.textContent = error.message || 'Unable to load payment histories.';
    renderPaymentHistoryRows([]);
    elements.txTableBody.firstElementChild.firstElementChild.textContent =
      'Payment history is unavailable. Check the database migration and try again.';
    return [];
  }
}

function exportPaymentHistoryCsv(records) {
  const headers = [
    'Transaction / Ref', 'Student ID', 'Student Name', 'Course / Year',
    'Amount Paid', 'Payment Method', 'Date & Time', 'Cashier Staff', 'Status'
  ];
  const rows = records.map(record => [
    record.reference_number,
    record.student_id,
    record.student_name,
    [record.course, record.year_level].filter(Boolean).join(' / '),
    Number(record.amount_paid || 0).toFixed(2),
    record.payment_method,
    record.created_at,
    record.cashier_staff,
    record.status
  ]);
  const csvValue = value => {
    let text = String(value ?? '');
    if (/^[=+\-@]/.test(text)) text = `'${text}`;
    return `"${text.replace(/"/g, '""')}"`;
  };
  const csv = [headers, ...rows].map(row => row.map(csvValue).join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' }));
  const link = createSafeElement('a');
  link.href = url;
  link.download = `ccdi-payment-histories-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
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
      const result = await response.json();
      if (!response.ok || !result.success || !result.csrf_token) {
        throw new Error(result.message || 'Cashier sign-in failed.');
      }
      elements.cashierLoginForm.reset();
      await startCashierSession(result.csrf_token);
    } catch (error) {
      elements.cashierLoginMessage.textContent = error.message || 'Cashier sign-in failed.';
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
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Unable to sign out.');
      }
      window.location.replace('student.html');
    } catch (error) {
      alert(error.message || 'Unable to sign out.');
    }
  });

  elements.navQueue.addEventListener('click', (e) => {
    e.preventDefault();
    switchTab(elements.pageQueue, elements.navQueue);
  });

  elements.navTransactions.addEventListener('click', (e) => {
    e.preventDefault();
    switchTab(elements.pageTransactions, elements.navTransactions);
    fetchPaymentHistories();
  });

  elements.navReports.addEventListener('click', (e) => {
    e.preventDefault();
    switchTab(elements.pageReports, elements.navReports);
  });

  elements.txSearchInput.addEventListener('input', () => {
    clearTimeout(paymentHistorySearchTimer);
    paymentHistorySearchTimer = setTimeout(() => {
      paymentHistoryPage = 1;
      fetchPaymentHistories();
    }, 250);
  });
  [elements.txFilterRange, elements.txFilterStatus].forEach(filter => {
    filter.addEventListener('change', () => {
      paymentHistoryPage = 1;
      fetchPaymentHistories();
    });
  });
  elements.paymentHistoryPrevious.addEventListener('click', () => {
    paymentHistoryPage = Math.max(1, paymentHistoryPage - 1);
    fetchPaymentHistories();
  });
  elements.paymentHistoryNext.addEventListener('click', () => {
    paymentHistoryPage++;
    fetchPaymentHistories();
  });
  elements.paymentExportBtn.addEventListener('click', async () => {
    elements.paymentExportBtn.disabled = true;
    try {
      const records = await fetchPaymentHistories({ exportAll: true });
      if (records.length === 0) {
        elements.paymentHistoryMessage.textContent = 'There are no matching records to export.';
        return;
      }
      exportPaymentHistoryCsv(records);
      elements.paymentHistoryMessage.textContent = `${records.length.toLocaleString('en-PH')} records exported.`;
    } catch (error) {
      elements.paymentHistoryMessage.textContent = error.message || 'Unable to export payment histories.';
    } finally {
      elements.paymentExportBtn.disabled = false;
    }
  });

  elements.openModalBtn.addEventListener('click', () => elements.dialog.showModal());
  elements.serviceTypeSelect.addEventListener('change', updateTuitionFields);
  // Spec 3: live payment breakdown on amount input
  elements.customDiscountInput.addEventListener('input', updateCashierPayBreakdown);

  const closeModal = () => elements.dialog.close();
  elements.closeModalBtn.addEventListener('click', closeModal);
  elements.closeModalCross.addEventListener('click', closeModal);

  elements.ticketForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const studentName = elements.studentNameInput.value.trim().replace(/\s+/g, ' ');
    const customValue = elements.customDiscountInput.value.trim();

    const studentId = elements.studentIdInput.value.trim();
    if (!studentName || !studentId) {
      alert('Enter both the Student ID and full name shown in the active CCDI student record.');
      return;
    }

    const isPercentage = customValue.includes('%');
    if (isPercentage && customValue.length > 32) {
      alert('Custom percentages must be 32 characters or fewer.');
      return;
    }

    const isTuitionPayment = elements.serviceTypeSelect.value === 'Tuition Payment';
    const created = await queueManager.createTicket({
      lane: elements.laneTypeSelect.value,
      studentId,
      studentName: studentName,
      service: elements.serviceTypeSelect.value,
      payment: isTuitionPayment ? elements.paymentTypeSelect.value : 'N/A (Document Only)',
      paymentPeriod: isTuitionPayment ? elements.paymentPeriodSelect.value : '',
      paymentPercentage: isTuitionPayment && isPercentage ? customValue : '',
      discount: isTuitionPayment && customValue && !isPercentage ? customValue : '',
      customAmount: isTuitionPayment && customValue && !isPercentage ? parseFloat(customValue) || 0 : 0
    });
    if (!created) return;
    elements.ticketForm.reset();
    elements.dialog.close();
  });

  elements.btnRegNext.addEventListener('click', async () => await queueManager.callNext('Regular'));
  elements.btnRegComplete.addEventListener('click', async () => {
    await queueManager.completeCurrent('Regular');
    await fetchPaymentHistories();
  });
  elements.btnRegSkip.addEventListener('click', async () => await queueManager.skipCurrent('Regular'));
  elements.btnRegRecall.addEventListener('click', async () => {
    const active = queueManager.getState().currentRegular;
    if (active) {
      const res = await queueManager.recallCurrent('Regular');
      if (res) {
        const attempt = res.data?.recall_count || (active.recallCount + 1);
        const msg = res.notification || `Paki-check ang queue: Ikaw ay tinatawag na sa Cashier (Attempt ${attempt}/3). Bumalik na sa pila.`;
        alert(`📱 ${msg}\nTicket: ${active.id}`);
      }
    }
  });

  elements.btnPrioNext.addEventListener('click', async () => await queueManager.callNext('Priority'));
  elements.btnPrioComplete.addEventListener('click', async () => {
    await queueManager.completeCurrent('Priority');
    await fetchPaymentHistories();
  });
  elements.btnPrioSkip.addEventListener('click', async () => await queueManager.skipCurrent('Priority'));
  elements.btnPrioRecall.addEventListener('click', async () => {
    const active = queueManager.getState().currentPriority;
    if (active) {
      const res = await queueManager.recallCurrent('Priority');
      if (res) {
        const attempt = res.data?.recall_count || (active.recallCount + 1);
        const msg = res.notification || `Paki-check ang queue: Ikaw ay tinatawag na sa Cashier (Attempt ${attempt}/3). Bumalik na sa pila.`;
        alert(`📱 ${msg}\nTicket: ${active.id}`);
      }
    }
  });

  elements.resetSystemBtn.addEventListener('click', async () => {
    if (!window.confirm("Are you sure you want to reset the queue? This will archive today's history.")) {
      return;
    }
    try {
      await queueManager.resetDay();
      await fetchPaymentHistories();
      alert('Operational day reset. The daily report was archived successfully.');
    } catch (error) {
      alert(error.message);
    }
  });
}

function init() {
  elements = {
    navQueue: document.getElementById('navQueue'),
    navTransactions: document.getElementById('navTransactions'),
    navReports: document.getElementById('navReports'),

    pageQueue: document.getElementById('pageQueue'),
    pageTransactions: document.getElementById('pageTransactions'),
    pageReports: document.getElementById('pageReports'),

    txSearchInput: document.getElementById('txSearchInput'),
    txFilterStatus: document.getElementById('txFilterStatus'),
    txFilterRange: document.getElementById('txFilterRange'),
    txTableBody: document.getElementById('txTableBody'),
    historyTotalCollections: document.getElementById('historyTotalCollections'),
    historyTotalTransactions: document.getElementById('historyTotalTransactions'),
    historyDailyRevenue: document.getElementById('historyDailyRevenue'),
    historyActiveQueue: document.getElementById('historyActiveQueue'),
    paymentCollectionsSpark: document.getElementById('paymentCollectionsSpark'),
    paymentTransactionsSpark: document.getElementById('paymentTransactionsSpark'),
    paymentDailySpark: document.getElementById('paymentDailySpark'),
    paymentActivityChart: document.getElementById('paymentActivityChart'),
    paymentActivitySummary: document.getElementById('paymentActivitySummary'),
    paymentHistoryMessage: document.getElementById('paymentHistoryMessage'),
    paymentHistoryPageInfo: document.getElementById('paymentHistoryPageInfo'),
    paymentHistoryPrevious: document.getElementById('paymentHistoryPrevious'),
    paymentHistoryNext: document.getElementById('paymentHistoryNext'),
    paymentExportBtn: document.getElementById('paymentExportBtn'),
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
    customDiscountInput: document.getElementById('customDiscount'),
    tuitionPaymentFields: document.getElementById('tuitionPaymentFields'),

    cashierLoginDialog: document.getElementById('cashierLoginDialog'),
    cashierLoginForm: document.getElementById('cashierLoginForm'),
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
    elements.customDiscountInput.value = '';
    const breakdown = document.getElementById('cashierPayBreakdown');
    if (breakdown) breakdown.style.display = 'none';
  }
}

// --- Spec 3: Live payment breakdown recalculation for cashier ---
let cashierStudentBalance = 0;

function updateCashierPayBreakdown() {
  const breakdown = document.getElementById('cashierPayBreakdown');
  if (!breakdown) return;
  const rawVal = parseFloat(elements.customDiscountInput.value);
  if (!rawVal || rawVal <= 0 || elements.serviceTypeSelect.value !== 'Tuition Payment') {
    breakdown.style.display = 'none';
    return;
  }
  const remaining = cashierStudentBalance > 0 ? Math.max(0, cashierStudentBalance - rawVal) : 0;
  const pct = cashierStudentBalance > 0
    ? Math.min(100, (rawVal / cashierStudentBalance) * 100).toFixed(1) + '%'
    : 'Custom Amount';
  const fmt = n => `\u20B1${n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  document.getElementById('cpAmountPay').textContent = fmt(rawVal);
  document.getElementById('cpRemaining').textContent = fmt(remaining);
  document.getElementById('cpPercent').textContent = pct;
  breakdown.style.display = 'block';
}

async function restoreCashierSession() {
  try {
    const response = await fetch(`${APP_CONFIG.apiUrl}?action=auth_status`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await response.json();
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'Unable to verify cashier session.');
    }
    if (result.authenticated && result.role === 'cashier' && result.csrf_token) {
      await startCashierSession(result.csrf_token);
      return;
    }
    document.body.classList.remove('auth-locked');
    elements.cashierLoginDialog.showModal();
  } catch (error) {
    document.body.classList.remove('auth-locked');
    elements.cashierLoginMessage.textContent = error.message || 'Unable to verify cashier session.';
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
  paymentHistoryPage = 1;
  fetchPaymentHistories();
  clearInterval(paymentHistoryRefreshTimer);
  paymentHistoryRefreshTimer = setInterval(fetchPaymentHistories, 30000);
}

document.addEventListener('DOMContentLoaded', init);