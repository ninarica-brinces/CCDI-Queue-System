import { APP_CONFIG } from './config.js';
import { QueueManager } from './queueManager.js';

let elements = {};
let queueManager;

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
  renderTransactionsPage(state);
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

function renderTransactionsPage(state) {
  const tbody = elements.txTableBody;
  tbody.innerHTML = '';

  const query = elements.txSearchInput.value.toLowerCase().trim();
  const filterStatus = elements.txFilterStatus.value;

  const filtered = state.transactions.filter(tx => {
    const matchesSearch = tx.id.toLowerCase().includes(query) || tx.studentName.toLowerCase().includes(query);
    const matchesStatus = filterStatus === 'ALL' || tx.status === filterStatus;
    return matchesSearch && matchesStatus;
  });

  if (filtered.length === 0) {
    const tr = createSafeElement('tr');
    const td = createSafeElement('td', 'empty-msg', 'No payment history found.');
    td.colSpan = 8;
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  filtered.slice().reverse().forEach(tx => {
    const tr = createSafeElement('tr');
    tr.appendChild(createSafeElement('td', '', tx.id));
    tr.appendChild(createSafeElement('td', '', tx.studentName));
    tr.appendChild(createSafeElement('td', '', tx.lane));
    tr.appendChild(createSafeElement('td', '', tx.service));
    tr.appendChild(createSafeElement('td', '', formatPaymentDetails(tx)));
    tr.appendChild(createSafeElement('td', '', tx.time));
    tr.appendChild(createSafeElement('td', '', tx.endTime));

    const tdStatus = createSafeElement('td');
    const statusClass = tx.status === 'Completed' ? 'badge-status status-completed' : 'badge-status status-skipped';
    tdStatus.appendChild(createSafeElement('span', statusClass, tx.status));
    tr.appendChild(tdStatus);

    tbody.appendChild(tr);
  });
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
          staffId: elements.cashierLoginStaffId.value.trim(),
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
  });

  elements.navReports.addEventListener('click', (e) => {
    e.preventDefault();
    switchTab(elements.pageReports, elements.navReports);
  });

  elements.txSearchInput.addEventListener('input', () => renderTransactionsPage(queueManager.getState()));
  elements.txFilterStatus.addEventListener('change', () => renderTransactionsPage(queueManager.getState()));

  elements.openModalBtn.addEventListener('click', () => elements.dialog.showModal());
  elements.serviceTypeSelect.addEventListener('change', updateTuitionFields);

  const closeModal = () => elements.dialog.close();
  elements.closeModalBtn.addEventListener('click', closeModal);
  elements.closeModalCross.addEventListener('click', closeModal);

  elements.ticketForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const studentName = elements.studentNameInput.value.trim();
    const customValue = elements.customDiscountInput.value.trim();

    const studentId = elements.studentIdInput.value.trim();
    if (!studentName || !studentId) {
      alert('Authorization failed: Student Name and Student ID must exactly match an authorized CCDI student record.');
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
      discount: isTuitionPayment && customValue && !isPercentage ? customValue : ''
    });
    if (!created) return;
    elements.ticketForm.reset();
    elements.dialog.close();
  });

  elements.btnRegNext.addEventListener('click', async () => await queueManager.callNext('Regular'));
  elements.btnRegComplete.addEventListener('click', async () => await queueManager.completeCurrent('Regular'));
  elements.btnRegSkip.addEventListener('click', async () => await queueManager.skipCurrent('Regular'));
  elements.btnRegRecall.addEventListener('click', async () => {
    const active = queueManager.getState().currentRegular;
    if (active && await queueManager.recallCurrent('Regular')) {
      alert(`📱 CCDI Queue Alert: Ticket ${active.id}, please return to the cashier counter.`);
    }
  });

  elements.btnPrioNext.addEventListener('click', async () => await queueManager.callNext('Priority'));
  elements.btnPrioComplete.addEventListener('click', async () => await queueManager.completeCurrent('Priority'));
  elements.btnPrioSkip.addEventListener('click', async () => await queueManager.skipCurrent('Priority'));
  elements.btnPrioRecall.addEventListener('click', async () => {
    const active = queueManager.getState().currentPriority;
    if (active && await queueManager.recallCurrent('Priority')) {
      alert(`📱 CCDI Queue Alert: Ticket ${active.id}, please return to the cashier counter.`);
    }
  });

  elements.resetSystemBtn.addEventListener('click', async () => {
    if (
      confirm('Reset the operational day? A summary report will be archived, then today\'s queue tickets will be cleared.') &&
      confirm('Final confirmation: permanently clear today\'s tickets and begin a fresh queue?')
    ) {
      try {
        await queueManager.resetDay();
        alert('Operational day reset. The daily report was archived successfully.');
      } catch (error) {
        alert(error.message);
      }
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
    txTableBody: document.getElementById('txTableBody'),
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
    cashierLoginStaffId: document.getElementById('cashierLoginStaffId'),
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
  }
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
}

document.addEventListener('DOMContentLoaded', init);