import t from '../common/t';
import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import AuthForm from './AuthForm';
import TicketsPanel, { ticketTypes } from '../common/TicketsPanel';
import TicketImagePicker from '../common/TicketImagePicker';

export const config = {};
export async function loadConfig() {
  Object.assign(config, await app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/ccot/config` }));
}

export class AuthPage extends Page {
  view() { return m('.CCOT', m('form', { onsubmit: (e) => this.form?.submit(e) }, m(AuthForm, { key: m.route.param('action'), action: m.route.param('action'), onready: (form) => { this.form = form; } }))); }
}

export class PolicyPage extends Page {
  oninit(vnode) { super.oninit(vnode); loadConfig().then(() => m.redraw()); }
  view() {
    const key = m.route.param('page');
    const title = { agreement: t("lib.user_agreement"), privacy: t("lib.privacy_policy"), rules: t("lib.community_rules"), faq: t("lib.faq_and_regulatory_information") }[key];
    const text = title ? config[key] || t("lib.the_operator_has_not_completed_this_page_please_use_complaints") : '';
    // Plain text remains escaped. Only HTTPS URLs become links; never render administrator HTML.
    const content = text.split(/(https:\/\/[^\s<>"'()（）。，；、？！]+)/g).map((part) => part.startsWith('https://')
      ? m('a', { href: part, target: '_blank', rel: 'noopener noreferrer' }, part) : part);
    return m('.CCOT', [m('h2', title || t("lib.page_not_found")), m('div.CCOT-policy', content)]);
  }
}

export class TicketsPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.values = { type: 'account', message: '', contact: '' };
    this.notice = '';
    this.busy = false;
    this.images = []; this.imagePickerKey = 0;
    this.createOpen = !app.session.user;
  }
  async submit(e) {
    e.preventDefault(); if (this.busy) return; this.busy = true;
    try {
      let body = this.values;
      if (this.images.length) {
        body = new FormData();
        Object.entries(this.values).forEach(([key, value]) => body.append(key, value));
        body.append('image_count', String(this.images.length));
        this.images.forEach((file) => body.append('images[]', file));
      }
      const result = await app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/ccot/tickets`, body, ...(this.images.length ? { serialize: (raw) => raw } : {}) });
      this.notice = t("lib.ticket_submitted", { message: result.message, number: result.number }); this.values.message = this.values.contact = '';
      this.images = []; this.imagePickerKey++;
      if (app.session.user) this.createOpen = false;
      if (app.session.user) await this.ticketPanel?.refresh();
    } catch (e) { this.notice = e.response?.errors?.[0]?.detail || t("lib.submission_failed"); }
    finally { this.busy = false; m.redraw(); }
  }
  view() {
    return m('.CCOT', [
      m('h2', t("lib.complaints_and_contact")), m('p', t("lib.tickets_are_visible_only_to_you_and_administrators_you_can")),
      config.contact && m('p', [t("lib.contact_email"), config.contact]),
      this.notice && m('.Alert', { role: 'status' }, this.notice),
      m('details.CCOT-ticket-create', { open: this.createOpen, ontoggle: (event) => { this.createOpen = event.target.open; } }, [m('summary', t("lib.create_a_ticket")), m('form', { onsubmit: (e) => this.submit(e) }, [
        m('.Form-group', [m('label', { for: 'ccot-ticket-type' }, t("lib.type")), m('select.FormControl', { id: 'ccot-ticket-type', value: this.values.type, onchange: (e) => { this.values.type = e.target.value; } }, Object.entries(ticketTypes).map(([value, text]) => m('option', { value }, text)))]),
        m('.Form-group', [m('label', { for: 'ccot-ticket-message' }, t("lib.details_and_related_links")), m('textarea.FormControl', { id: 'ccot-ticket-message', rows: 5, required: true, maxlength: 4000, value: this.values.message, oninput: (e) => { this.values.message = e.target.value; } })]),
        m('.Form-group', [m('label', { for: 'ccot-ticket-contact' }, t("lib.contact_information")), m('input.FormControl', { id: 'ccot-ticket-contact', required: !app.session.user, maxlength: 200, value: this.values.contact, oninput: (e) => { this.values.contact = e.target.value; } })]),
        m('div', m(TicketImagePicker, { key: this.imagePickerKey, disabled: this.busy, onchange: (images) => { this.images = images; } })),
        m(Button, { type: 'submit', className: 'Button Button--primary', loading: this.busy }, t("lib.submit_ticket")),
      ])]),
      app.session.user && [m('h3', t("lib.my_tickets")), m(TicketsPanel, { admin: false, onready: (panel) => { this.ticketPanel = panel; } })],
    ]);
  }
}
