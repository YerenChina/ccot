import t from './t';
import ConfirmActionModal from './ConfirmActionModal';
import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

export const ticketTypes = { get report() { return t("lib.report"); }, get appeal() { return t("lib.appeal"); }, get account() { return t("lib.account_help"); }, get privacy() { return t("lib.personal_information_request"); } };
export const ticketStatuses = { get pending() { return t("lib.pending"); }, get working() { return t("lib.in_progress"); }, get closed() { return t("lib.closed"); } };
const dateFormat = new Intl.DateTimeFormat('zh-CN', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false });
export const ticketDate = (timestamp) => timestamp ? dateFormat.format(new Date(Number(timestamp) * 1000)) : '—';
const bucket = () => ({ tickets: [], total: 0, page: 1, loading: false, loaded: false, error: '', sequence: 0 });

export default class TicketsPanel extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.active = bucket(); this.history = bucket(); this.counts = { pending: 0, working: 0, closed: 0, active: 0 };
    this.openTickets = new Set(); this.drafts = new Map(); this.errors = new Map(); this.notice = '';
    this.attrs.onready?.(this);
    this.load('active');
  }
  onremove() { this.removed = true; this.attrs.onready?.(null); }
  async load(scope, page = this[scope].page) {
    const state = this[scope]; const sequence = ++state.sequence;
    state.loading = true; state.error = ''; m.redraw();
    try {
      const result = await app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/ccot/tickets?scope=${scope}&page=${page}${this.attrs.admin ? '' : '&mine=1'}` });
      if (this.removed || sequence !== state.sequence) return;
      state.tickets.forEach((ticket) => { if (!this.changed(ticket)) this.drafts.delete(ticket.id); });
      Object.assign(state, { tickets: result.tickets, total: result.total, page: result.page, loaded: true });
      this.counts = result.counts;
      if (this.attrs.admin) { this.imageStorage = result.imageStorage; this.retentionDays = result.retentionDays; }
    } catch (error) {
      if (!this.removed && sequence === state.sequence) state.error = error.response?.errors?.[0]?.detail || t("lib.could_not_load_tickets_please_retry_later");
    } finally {
      if (!this.removed && sequence === state.sequence) { state.loading = false; m.redraw(); }
    }
  }
  async refresh() { await Promise.all([this.load('active'), this.history.loaded || this.historyOpen ? this.load('history') : Promise.resolve()]); }
  draft(ticket) {
    if (!this.drafts.has(ticket.id)) this.drafts.set(ticket.id, { status: ticket.status, reply: ticket.reply || '', hold: !!ticket.hold });
    return this.drafts.get(ticket.id);
  }
  changed(ticket) {
    const draft = this.drafts.get(ticket.id);
    return draft && (draft.status !== ticket.status || draft.reply !== (ticket.reply || '') || draft.hold !== !!ticket.hold);
  }
  hasUnsaved() { return [...this.active.tickets, ...this.history.tickets].some((ticket) => this.changed(ticket)); }
  async save(ticket) {
    if (this.saving) return;
    this.saving = ticket.id; this.errors.delete(ticket.id); this.notice = '';
    try {
      const saved = { ...this.draft(ticket) };
      await app.request({ method: 'PATCH', url: `${app.forum.attribute('apiUrl')}/ccot/tickets/${ticket.id}`, body: saved });
      const closed = saved.status === 'closed';
      Object.assign(ticket, saved);
      this.drafts.delete(ticket.id);
      this.notice = t("lib.ticket_saved", { number: ticket.number, suffix: closed ? t("lib.moved_to_ticket_history") : '。' });
      await this.refresh();
    } catch (error) { this.errors.set(ticket.id, error.response?.errors?.[0]?.detail || t("lib.save_failed_please_retry")); }
    finally { this.saving = null; m.redraw(); }
  }
  async deleteTicket(ticket) {
    if (!this.attrs.admin || this.saving || ticket.hold || this.draft(ticket).hold || this.hasUnsaved()) return;
    app.modal.show(ConfirmActionModal, { title: t('lib.delete_ticket'), message: t('lib.confirm_delete_ticket', { number: ticket.number }), run: () => this.performDelete(ticket) });
  }
  async performDelete(ticket) {
    if (!this.attrs.admin || this.saving || ticket.hold || this.draft(ticket).hold || this.hasUnsaved()) return;
    this.saving = ticket.id; this.errors.delete(ticket.id); this.notice = '';
    try {
      const result = await app.request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/ccot/tickets/${ticket.id}` });
      this.drafts.delete(ticket.id); this.openTickets.delete(ticket.id);
      this.notice = `${ticket.number}：${result.message}`;
      await this.refresh();
    } catch (error) { this.errors.set(ticket.id, error.response?.errors?.[0]?.detail || t("lib.delete_failed_please_retry")); throw error; }
    finally { this.saving = null; m.redraw(); }
  }
  images(ticket) {
    return ticket.images?.length ? [m('h4', t("lib.image_attachments")), m('.CCOT-ticket-images', ticket.images.map((image, index) => {
      const url = `${app.forum.attribute('apiUrl')}/ccot/tickets/${ticket.id}/images/${image.id}`;
      return m('figure', { key: image.id }, [m('a', { href: url, target: '_blank', rel: 'noopener noreferrer' }, m('img', { src: url, alt: t("lib.ticket_image", { number: index + 1 }), loading: 'lazy', referrerpolicy: 'no-referrer' })), m('figcaption', t("lib.image_open", { number: index + 1, size: Math.ceil(image.size / 1024) }))]);
    }))] : null;
  }
  status(status) { return m(`span.CCOT-ticket-status.CCOT-ticket-status--${status}`, [m('span.CCOT-status-dot', { 'aria-hidden': 'true' }), ticketStatuses[status] || t("lib.unknown_status")]); }
  ticket(ticket) {
    const admin = this.attrs.admin;
    const draft = admin ? this.draft(ticket) : null;
    return m('details.CCOT-ticket', { key: ticket.id, open: this.openTickets.has(ticket.id), ontoggle: (event) => {
      if (event.target.open) this.openTickets.add(ticket.id); else this.openTickets.delete(ticket.id);
    } }, [
      m('summary.CCOT-ticket-summary', [
        m('span.CCOT-ticket-chevron', { 'aria-hidden': 'true' }, '›'),
        m('span.CCOT-ticket-title', [m('strong', ticket.number || `#${ticket.id}`), m('span.CCOT-ticket-type', ticketTypes[ticket.type])]),
        this.status(ticket.status),
        m('time.CCOT-ticket-time', { datetime: new Date(Number(ticket.created_at) * 1000).toISOString() }, ticketDate(ticket.created_at)),
        admin && !!ticket.hold && m('span.CCOT-ticket-held', t("lib.held")),
      ]),
      m('.CCOT-ticket-content', [
        m('.CCOT-ticket-meta', [m('span', t("lib.submitted_at", { date: ticketDate(ticket.created_at) })), m('span', t("lib.updated_at", { date: ticketDate(ticket.updated_at) })), admin && m('span', ticket.user_id ? t("lib.member_ticket") : t("lib.guest_ticket"))]),
        m('h4', t("lib.ticket_details")), m('p.CCOT-policy', ticket.message),
        admin && ticket.contact && m('p.CCOT-policy', [t("lib.contact"), ticket.contact]),
        this.images(ticket),
        admin ? [
          m('.Form-group', [m('label', { for: `ccot-ticket-status-${ticket.id}` }, t("lib.status")), m('select.FormControl', { id: `ccot-ticket-status-${ticket.id}`, value: draft.status, disabled: !!this.saving, onchange: (event) => { draft.status = event.target.value; } }, Object.entries(ticketStatuses).map(([value, label]) => m('option', { value }, label)))]),
          m('.Form-group', [m('label', { for: `ccot-ticket-reply-${ticket.id}` }, t("lib.administrator_reply")), m('textarea.FormControl', { id: `ccot-ticket-reply-${ticket.id}`, rows: 4, maxlength: 4000, value: draft.reply, disabled: !!this.saving, oninput: (event) => { draft.reply = event.target.value; } })]),
          m('label.CCOT-check', [m('input', { type: 'checkbox', checked: draft.hold, disabled: !!this.saving, onchange: (event) => { draft.hold = event.target.checked; } }), " " + t("lib.preserve_prevent_deletion_and_expiry_cleanup_including_images")]),
          this.errors.has(ticket.id) && m('.Alert', { role: 'alert' }, this.errors.get(ticket.id)),
          m('.CCOT-ticket-actions', [
            m(Button, { type: 'button', className: 'Button Button--primary', loading: this.saving === ticket.id, disabled: !!this.saving || !this.changed(ticket), onclick: () => this.save(ticket) }, t("lib.save_changes")),
            this.changed(ticket) && m(Button, { type: 'button', className: 'Button', disabled: !!this.saving, onclick: () => { this.drafts.delete(ticket.id); this.errors.delete(ticket.id); } }, t("lib.cancel_changes")),
            m(Button, { type: 'button', className: 'Button Button--danger', disabled: !!this.saving || !!ticket.hold || draft.hold || this.hasUnsaved(), onclick: () => this.deleteTicket(ticket) }, t("lib.delete_ticket")),
          ]),
          (!!ticket.hold || draft.hold) && m('p.CCOT-help', t("lib.held_tickets_cannot_be_deleted_review_and_save_the_removal")),
        ] : [m('h4', t("lib.administrator_reply")), m('p.CCOT-policy', ticket.reply || t("lib.no_reply_yet_please_wait"))],
      ]),
    ]);
  }
  list(scope) {
    const state = this[scope]; const pages = Math.max(1, Math.ceil(state.total / 50));
    return [
      state.loading && m('p.CCOT-help', { role: 'status' }, t("lib.loading_tickets")),
      state.error && m('.Alert', { role: 'alert' }, [state.error, ' ', m(Button, { type: 'button', className: 'Button Button--link', onclick: () => this.load(scope) }, t("lib.retry"))]),
      !state.loading && !state.error && !state.tickets.length && m('.CCOT-ticket-empty', scope === 'history' ? t("lib.no_ticket_history") : t("lib.no_active_tickets")),
      state.tickets.map((ticket) => this.ticket(ticket)),
      state.total > 0 && m('.CCOT-ticket-pagination', [
        m('span.CCOT-help', t("lib.ticket_pagination", { total: state.total, page: state.page, pages: pages })),
        m(Button, { type: 'button', className: 'Button', disabled: state.loading || !!this.saving || this.hasUnsaved() || state.page <= 1, onclick: () => this.load(scope, state.page - 1) }, t("lib.previous_page")),
        m(Button, { type: 'button', className: 'Button', disabled: state.loading || !!this.saving || this.hasUnsaved() || state.page >= pages, onclick: () => this.load(scope, state.page + 1) }, t("lib.next_page")),
      ]),
    ];
  }
  view() {
    return m('.CCOT-ticket-panel', [
      this.attrs.admin && this.imageStorage && m('.CCOT-ticket-storage', [
        m('p.CCOT-help', t("lib.ticket_retention", { days: this.retentionDays })),
        m('p.CCOT-help', [t("lib.image_storage"), m('code', this.imageStorage.replace(/\\/g, '/').split('/').filter(Boolean).slice(-5).join('/'))]),
      ]),
      m('.CCOT-ticket-stats', Object.entries(ticketStatuses).map(([status, label]) => m('div', [this.status(status), m('strong', String(this.counts[status] || 0))]))),
      this.notice && m('.Alert', { role: 'status' }, this.notice),
      m('.CCOT-ticket-toolbar', [m('h3', t("lib.active_ticket_count", { count: this.counts.active })), m(Button, { type: 'button', className: 'Button', loading: this.active.loading || this.history.loading, disabled: !!this.saving || this.hasUnsaved(), onclick: () => this.refresh() }, t("lib.refresh_tickets"))]),
      m('p.CCOT-help', t("lib.pending_tickets_come_first_newest_first_within_each_status_expand")),
      this.hasUnsaved() && m('p.CCOT-help', t("lib.save_or_cancel_your_changes_before_changing_pages_or_refreshing")),
      this.list('active'),
      m('details.CCOT-ticket-history', { open: !!this.historyOpen, ontoggle: (event) => {
        this.historyOpen = event.target.open;
        if (this.historyOpen && !this.history.loaded && !this.history.loading) this.load('history');
      } }, [
        m('summary', [m('strong', t("lib.history_ticket_count", { count: this.counts.closed })), m('span.CCOT-help', t("lib.closed_expand_to_view"))]),
        this.historyOpen && this.list('history'),
      ]),
    ]);
  }
}
