import { APP_CONFIG } from './config.js';

const EMPTY_STATE = {
  regularQueue: [],
  priorityQueue: [],
  currentRegular: null,
  currentPriority: null,
  transactions: [],
  counters: { regSequence: 0, prioSequence: 0, completed: 0, skipped: 0 }
};

export class QueueManager {
  #state = structuredClone(EMPTY_STATE);
  #listeners = [];
  #statusListeners = [];
  #isFetching = false;

  constructor() {
    this.init();
  }

  async init() {
    await this.fetchState();
    setInterval(() => this.fetchState(), 1500);
  }

  async fetchState() {
    if (this.#isFetching) return;

    this.#isFetching = true;
    try {
      const response = await fetch(`${APP_CONFIG.apiUrl}?action=get_state`, { cache: 'no-store' });
      const result = await response.json();

      if (!response.ok || !result.success || !Array.isArray(result.data)) {
        throw new Error(result.message || 'Failed to load queue state');
      }

      this.#processDatabaseTickets(result.data);
      this.#notify();
      this.#setSyncStatus('Connected to PostgreSQL');
    } catch (error) {
      console.error('Failed to fetch queue state:', error);
      this.#setSyncStatus('PostgreSQL disconnected');
    } finally {
      this.#isFetching = false;
    }
  }

  #processDatabaseTickets(tickets) {
    const remoteState = structuredClone(EMPTY_STATE);

    tickets.forEach(ticket => {
      const mapped = {
        id: ticket.ticket_number,
        dbId: ticket.ticket_id,
        lane: ticket.lane,
        studentName: ticket.student_name,
        service: ticket.transaction_type,
        payment: ticket.payment_method,
        status: ticket.status,
        time: ticket.created_at,
        endTime: ticket.completed_at || '--'
      };

      const sequence = Number(ticket.ticket_number.split('-')[1]) || 0;
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

  subscribe(listener) {
    this.#listeners.push(listener);
    listener(this.getState());
  }

  subscribeToSyncStatus(listener) {
    this.#statusListeners.push(listener);
    listener('Connecting to PostgreSQL');
  }

  async createTicket({ lane, studentId, studentName, service, payment, discount }) {
    try {
      const response = await fetch(APP_CONFIG.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'create_ticket',
          lane,
          studentId,
          studentName,
          service,
          payment,
          discount
        })
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Failed to create ticket');
      }

      await this.fetchState();
      return true;
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
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'call_next', lane })
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Failed to call the next ticket');
      }

      this.announceTicket({
        id: result.data.ticket_number,
        lane: result.data.lane
      });
      await this.fetchState();
      return true;
    } catch (error) {
      console.error('Failed to call next ticket:', error);
      alert(error.message || 'Failed to call next ticket');
      await this.fetchState();
      return false;
    }
  }

  async completeCurrent(lane) {
    await this.#endCurrent(lane, 'completed');
  }

  async skipCurrent(lane) {
    await this.#endCurrent(lane, 'cancelled'); // Sa DB natin, 'cancelled' ang katumbas ng skipped
  }

  async #endCurrent(lane, status) {
    const isPriority = lane === 'Priority';
    const currentKey = isPriority ? 'currentPriority' : 'currentRegular';
    const ticket = this.#state[currentKey];

    if (!ticket) return;

    try {
      await this.#updateTicketStatus(ticket.dbId, status);
      await this.fetchState();
    } catch (error) {
      console.error('Failed to update ticket status:', error);
      alert(error.message || 'Failed to update ticket status');
    }
  }

  async #updateTicketStatus(ticketId, status) {
    const response = await fetch(APP_CONFIG.apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'update_status',
        ticket_id: ticketId,
        status: status
      })
    });
    const result = await response.json();
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
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reset_day' })
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Failed to reset queue');
      }
      await this.fetchState();
    } catch (error) {
      console.error('Failed to reset day:', error);
      throw error;
    }
  }
}