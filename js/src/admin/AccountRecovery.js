import t from '../common/t';
import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

export default class AccountRecovery extends Component {
  oninit(vnode) { super.oninit(vnode); this.phone = ''; this.notice = ''; }
  async submit(event) {
    event.preventDefault();
    if (this.busy || !/^1[3-9][0-9]{9}$/.test(this.phone)) return;
    if (!window.confirm(t("lib.has_this_phone_number_been_reviewed_and_approved_to_remove"))) return;
    this.busy = true; this.notice = '';
    try {
      await app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/ccot/block`, body: { phone: this.phone, blocked: false } });
      this.phone = ''; this.notice = t("lib.phone_restrictions_removed");
    } catch (error) { this.notice = error.response?.errors?.[0]?.detail || t("lib.the_operation_failed_please_retry_later"); }
    finally { this.busy = false; m.redraw(); }
  }
  view() {
    return m('section.CCOT-account-recovery', [
      m('h2', t("lib.account_review")),
      m('p', t("lib.review_recycled_numbers_or_mistaken_restrictions_manually_verify_account_ownership")),
      m('p.CCOT-help', t("lib.administrators_cannot_mark_an_unverified_account_as_phone_verified_removing")),
      m('form', { onsubmit: (event) => this.submit(event) }, [
        m('.Form-group', [m('label', { for: 'ccot-review-phone' }, t("lib.phone_number_to_review")), m('input.FormControl', { id: 'ccot-review-phone', type: 'tel', maxlength: 11, autocomplete: 'off', pattern: '1[3-9][0-9]{9}', required: true, value: this.phone, oninput: (event) => { this.phone = event.target.value; } })]),
        this.notice && m('.Alert', { role: 'status' }, this.notice),
        m(Button, { type: 'submit', className: 'Button Button--primary', loading: this.busy, disabled: this.busy || !/^1[3-9][0-9]{9}$/.test(this.phone) }, t("lib.remove_after_manual_review")),
      ]),
    ]);
  }
}
