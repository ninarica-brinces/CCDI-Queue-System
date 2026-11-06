import { APP_CONFIG } from './config.js';

const EMPTY_STATE = {
  regularQueue: [],
  priorityQueue: [],
  currentRegular: null,
  currentPriority: null,
  transactions: [],
  counters: { regSequence: 0, prioSequence: 0, completed: 0, skipped: 0 }
};

async function readApiResponse(response) {
  let result;
  try {
    result = await response.json();
  } catch {
    const error = new Error(`The server returned an invalid response (HTTP ${response.status}).`);
    error.status = response.status;
    throw error;
  }
  if (!result || typeof result !== 'object' || Array.isArray(result)) {
    const error = new Error(`The server returned an invalid response (HTTP ${response.status}).`);
    error.status = response.status;
    throw error;
  }
  return result;
}

export class QueueManager {
  #state = structuredClone(EMPTY_STATE);
  #listeners = [];
  #statusListeners = [];
  #fetchPromise = null;
  #csrfToken;

  constructor(csrfToken) {
    this.#csrfToken = csrfToken;
    this.init();
  }

  async init() {
    this.fetchState().catch(() => undefined);
    setInterval(() => this.fetchState().catch(() => undefined), 1500);
  }

  async fetchState(retries = 3, delay = 1000) {
    if (this.#fetchPromise) return this.#fetchPromise;

    const request = this.#fetchStateWithRetry(retries, delay);
    this.#fetchPromise = request;
    try {
      return await request;
    } finally {
      if (this.#fetchPromise === request) this.#fetchPromise = null;
    }
  }

  async #fetchStateWithRetry(retries, delay) {
    let currentDelay = delay;
    for (let attempt = 0; ; attempt++) {
      try {
        const response = await fetch('api.php?action=get_queue_state', { cache: 'no-store' });
        if (!response.ok) {
          let message = `Service Unavailable: ${response.status}`;
          try {
            const errorResult = await readApiResponse(response);
            if (errorResult.message) message = errorResult.message;
          } catch {
            // Keep the HTTP status message when the server does not return JSON.
          }
          const error = new Error(message);
          error.status = response.status;
          throw error;
        }

        const result = await readApiResponse(response);
        if (!result.success || !Array.isArray(result.data)) {
          throw new Error(result.message || 'Failed to load queue state');
        }

        this.#processDatabaseTickets(result.data);
        this.#notify();
        this.#setSyncStatus('Connected to PostgreSQL');
        return result;
      } catch (error) {
        if (error.status === 401) {
          window.location.replace('cashier.html');
          throw error;
        }
        if (attempt >= retries) {
          console.error('Failed to fetch queue state:', error);
          this.#setSyncStatus('PostgreSQL disconnected');
          throw error;
        }

        this.#setSyncStatus('Reconnecting...');
        await new Promise(resolve => setTimeout(resolve, currentDelay));
        currentDelay *= 2;
      }
    }
  }

  #processDatabaseTickets(tickets) {
    const remoteState = structuredClone(EMPTY_STATE);

    tickets.forEach(ticket => {
      const ticketNumber = String(ticket.ticket_number || `Ticket-${ticket.ticket_id}`);
      const mapped = {
        id: ticketNumber,
        dbId: ticket.ticket_id,
        lane: ticket.lane,
        studentName: ticket.student_name || '',
        studentId: ticket.student_id || '',
        course: ticket.course || '',
        address: ticket.address || '',
        yearLevel: ticket.year_level || '',
        service: ticket.transaction_type || '',
        payment: ticket.payment_description || '',
        paymentPeriod: ticket.payment_period || '',
        recallCount: Number(ticket.recall_count) || 0,
        notes: ticket.notes || '',
        status: ticket.status,
        time: ticket.created_at,
        endTime: ticket.completed_at || '--'
      };

      const sequence = Number(ticketNumber.split('-')[1]) || 0;
      if (ticket.lane === 'Priority') {
        remoteState.counters.prioSequence = Math.max(remoteState.counters.prioSequence, sequence);
      } else {
        remoteState.counters.regSequence = Math.max(remoteState.counters.regSequence, sequence);
      }

      if (ticket.status === 'waiting') {
        if (ticket.lane === 'Priority') remoteState.priorityQueue.push(mapped);
        else remoteState.regularQueue.push(mapped);
      } else if (ticket.status === 'serving') {
        if (ticket.lane === 'Priority') remoteState.currentPriority = mapped;
        else remoteState.currentRegular = mapped;
      } else if (ticket.status === 'completed' || ticket.status === 'cancelled') {
        mapped.status = ticket.status === 'completed' ? 'Completed' : 'Skipped';
        remoteState.transactions.push(mapped);
        remoteState.counters[ticket.status === 'completed' ? 'completed' : 'skipped']++;
      }
    });

    this.#state = remoteState;
  }

  #notify() {
    const snapshot = this.getState();
    this.#listeners.forEach(listener => listener(snapshot));
  }

  #setSyncStatus(status) {
    this.#statusListeners.forEach(listener => listener(status));
  }

  getState() {
    return structuredClone(this.#state);
  }

  get csrfToken() {
    return this.#csrfToken;
  }

  subscribe(listener) {
    this.#listeners.push(listener);
    listener(this.getState());
  }

  subscribeToSyncStatus(listener) {
    this.#statusListeners.push(listener);
    listener('Connecting to PostgreSQL');
  }

  async createTicket({
    lane,
    studentId,
    studentName,
    service,
    payment,
    paymentPeriod
  }) {
    try {
      const normalizedStudentId = String(studentId ?? '').trim();
      const normalizedStudentName = String(studentName ?? '').trim().replace(/\s+/g, ' ');
      const response = await fetch(APP_CONFIG.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.#csrfToken },
        body: JSON.stringify({
          action: 'create_ticket',
          lane,
          studentId: normalizedStudentId,
          studentName: normalizedStudentName,
          service,
          payment,
          paymentPeriod
        })
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) {
        if (response.status === 403 && result.code === 'STUDENT_RECORD_MISMATCH') {
          throw new Error(
            'We could not verify this student. Check that the Student ID and full name match an active CCDI student record, then try again.'
          );
        }
        throw new Error(result.message || 'Failed to create ticket');
      }

      await this.fetchState().catch(() => undefined);
      return result;
    } catch (error) {
      console.error('Error creating ticket:', error);
      alert(error.message || 'Failed to create ticket');
      return false;
    }
  }

  async callNext(lane) {
    try {
      const response = await fetch(APP_CONFIG.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.#csrfToken },
        body: JSON.stringify({ action: 'call_next', lane })
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Failed to call the next ticket');
      }

      this.announceTicket({
        id: result.data.ticket_number,
        lane: result.data.lane
      });
      await this.fetchState().catch(() => undefined);
      return true;
    } catch (error) {
      console.error('Failed to call next ticket:', error);
      alert(error.message || 'Failed to call next ticket');
      await this.fetchState().catch(() => undefined);
      return false;
    }
  }

  async completeCurrent(lane) {
    await this.#endCurrent(lane, 'completed');
  }

  async skipCurrent(lane) {
    await this.#endCurrent(lane, 'cancelled'); // Sa DB natin, 'cancelled' ang katumbas ng skipped
  }

  async recallCurrent(lane) {
    const ticket = lane === 'Priority'
      ? this.#state.currentPriority
      : this.#state.currentRegular;
    if (!ticket) return false;
    if (ticket.recallCount >= 3) {
      alert('This ticket has reached the maximum of 3 recalls.');
      return false;
    }

    try {
      const response = await fetch(APP_CONFIG.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.#csrfToken },
        body: JSON.stringify({ action: 'recall_ticket', ticket_id: ticket.dbId })
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Failed to recall ticket');
      }

      await this.fetchState().catch(() => undefined);
      return true;
    } catch (error) {
      console.error('Failed to recall ticket:', error);
      alert(error.message || 'Failed to recall ticket');
      await this.fetchState().catch(() => undefined);
      return false;
    }
  }

  async #endCurrent(lane, status) {
    const isPriority = lane === 'Priority';
    const currentKey = isPriority ? 'currentPriority' : 'currentRegular';
    const ticket = this.#state[currentKey];

    if (!ticket) return;

    try {
      await this.#updateTicketStatus(ticket.dbId, status);
      await this.fetchState().catch(() => undefined);
    } catch (error) {
      console.error('Failed to update ticket status:', error);
      alert(error.message || 'Failed to update ticket status');
    }
  }

  async #updateTicketStatus(ticketId, status) {
    const response = await fetch(APP_CONFIG.apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.#csrfToken },
      body: JSON.stringify({
        action: 'update_status',
        ticket_id: ticketId,
        status: status
      })
    });
    const result = await readApiResponse(response);
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'Failed to update ticket status');
    }
  }

  announceTicket(ticket) {
    if (typeof window !== 'undefined' && 'speechSynthesis' in window && typeof SpeechSynthesisUtterance !== 'undefined') {
      window.speechSynthesis.cancel();
      const speech = new SpeechSynthesisUtterance(
        `Ticket number ${ticket.id}, ${ticket.lane} lane. Please proceed to the cashier counter.`
      );
      speech.rate = 0.9;
      window.speechSynthesis.speak(speech);
    }
  }

  async resetDay() {
    try {
      const response = await fetch(APP_CONFIG.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.#csrfToken },
        body: JSON.stringify({ action: 'reset_day' })
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Failed to reset queue');
      }
      await this.fetchState().catch(() => undefined);
    } catch (error) {
      console.error('Failed to reset day:', error);
      throw error;
    }
  }
}