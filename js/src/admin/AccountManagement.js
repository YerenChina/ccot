import t from '../common/t';
import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import AccountRecovery from './AccountRecovery';

export default class AccountManagement extends Component {
  oninit(vnode) { super.oninit(vnode); this.state = null; this.notice = ''; this.busy = false; this.confirmDelete = false; this.load(); }
  onremove() { this.removed = true; }
  endpoint(action) { return `${app.forum.attribute('apiUrl')}/ccot/account-${action}`; }
  async load() {
    try { const state = await app.request({ method: 'GET', url: this.endpoint('state') }); if (!this.removed) this.state = state; }
    catch (error) { if (!this.removed) this.notice = error.response?.errors?.[0]?.detail || t("lib.could_not_load_account_management_status_please_retry"); }
    finally { if (!this.removed) m.redraw(); }
  }
  async action(action, body = {}) {
    if (this.busy) return;
    this.busy = true; this.notice = '';
    try {
      const state = await app.request({ method: 'POST', url: this.endpoint(action), body });
      if (!this.removed) { this.state = state; app.data.settings.allow_sign_up = state.allowSignUp ? '1' : '0'; this.confirmDelete = false; }
    } catch (error) { if (!this.removed) this.notice = error.response?.errors?.[0]?.detail || t("lib.the_operation_failed_please_retry"); }
    finally { if (!this.removed) { this.busy = false; m.redraw(); } }
  }
  async setLegacyEntry(show) {
    if (this.busy) return;
    this.busy = true; this.notice = '';
    const value = show ? '1' : '0';
    try {
      await app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/settings`, body: { 'bushcraftcn-ccot.show_legacy_entry': value } });
      app.data.settings['bushcraftcn-ccot.show_legacy_entry'] = value;
    } catch (error) { if (!this.removed) this.notice = error.response?.errors?.[0]?.detail || t("lib.could_not_save_entry_visibility_please_retry"); }
    finally { if (!this.removed) { this.busy = false; m.redraw(); } }
  }
  async download() {
    if (this.busy) return;
    this.busy = true; this.notice = '';
    try {
      const response = await fetch(this.endpoint('export'), { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': app.session.csrfToken, Accept: 'text/html' } });
      if (!response.ok) { const body = await response.json().catch(() => null); throw new Error(body?.errors?.[0]?.detail || t("lib.export_failed_check_account_maintenance_status_and_retry")); }
      const blob = await response.blob(); const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'CCOT-phone-usernames.html'; document.body.append(anchor); anchor.click(); anchor.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      if (!this.removed) this.notice = t("lib.the_mapping_was_downloaded_open_it_locally_check_it_store");
      await this.load();
    } catch (error) { if (!this.removed) this.notice = error.message || t("lib.download_failed_please_retry"); }
    finally { if (!this.removed) { this.busy = false; m.redraw(); } }
  }
  async uninstall() {
    if (this.busy || !this.confirmDelete || !(this.state?.exportSaved || this.state?.phase === 'purging')) return;
    this.busy = true; this.notice = '';
    try { await app.request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/extensions/bushcraftcn-ccot` }); window.location.reload(); }
    catch (error) { if (!this.removed) this.notice = error.response?.errors?.[0]?.detail || t("lib.uninstall_did_not_finish_some_data_may_already_be_removed"); await this.load(); }
    finally { if (!this.removed) { this.busy = false; m.redraw(); } }
  }
  view() {
    const state = this.state;
    const purging = state?.phase === 'purging';
    const ready = state?.exportSaved && state.checks.every((check) => check.ok);
    const uninstallPage = this.attrs.page === 'uninstall';
    return m('.CCOT-account-management', [
      !uninstallPage && m('section.CCOT-account-section', [m('h2', t("lib.registration_control")),
        state && m(Switch, { state: state.allowSignUp, loading: this.busy, disabled: this.busy || purging, onchange: (open) => this.action('registration', { open }) }, t("lib.allow_new_account_registration")),
        m('p.CCOT-help', t("lib.uses_the_native_registration_setting_closing_registration_affects_new_accounts")),
        m('p.CCOT-help', t('lib.disable_and_enable_notice')),
        !state && m(Button, { className: 'Button', onclick: () => this.load() }, t("lib.reload_account_status")),
      ]),
      !uninstallPage && m('section.CCOT-account-section', [m('h2', t("lib.existing_user_entry")),
        m(Switch, { state: app.data.settings['bushcraftcn-ccot.show_legacy_entry'] !== '0', loading: this.busy, disabled: this.busy || purging, onchange: (show) => this.setLegacyEntry(show) }, t("lib.show_the_phone_verification_entry_for_existing_users")),
        m('p.CCOT-help', t("lib.when_hidden_account_pages_and_login_dialogs_omit_the_existing")),
      ]),
      !uninstallPage && m(AccountRecovery),
      uninstallPage && m('section.CCOT-account-section.CCOT-uninstall', [m('h2', t("lib.uninstall_preparation")),
        m('p', t("lib.close_registration_then_export_one_phone_to_username_mapping_you")),
        state && !state.preparing && m(Button, { className: 'Button', disabled: this.busy, onclick: () => this.action('start') }, t("lib.begin_uninstall_preparation")),
        state?.preparing && [
          !purging && m('.CCOT-account-actions', [m(Button, { className: 'Button Button--primary', disabled: this.busy || state.allowSignUp, onclick: () => this.download() }, state.exportValid ? t("lib.export_a_fresh_mapping") : t("lib.export_mapping")), m(Button, { className: 'Button', disabled: this.busy, onclick: () => this.action('cancel') }, t("lib.cancel_preparation"))]),
          m('p.CCOT-help', t("lib.the_mapping_is_an_offline_html_file_without_passwords_after")),
          !purging && m('ul.CCOT-account-checks', state.checks.map((check) => m('li', [m('span', { className: check.ok ? 'CCOT-check-ok' : 'CCOT-check-pending' }, check.ok ? '✓ ' : '○ '), check.label]))),
          !purging && m('label.CCOT-check', [m('input', { type: 'checkbox', checked: !!state.exportSaved, disabled: this.busy || !state.exportValid || state.exportSaved, onchange: (event) => { if (event.target.checked) this.action('saved', { saved: true }); } }), " " + t("lib.i_have_checked_and_privately_saved_the_latest_mapping_locally")]),
          purging && m('p.Alert', t("lib.cleanup_is_incomplete_retry_uninstall_preparation_cannot_be_cancelled_and")),
          m('p.CCOT-help', t("lib.uninstall_permanently_removes_ccot_settings_phone_bindings_keys_and_all")),
          m('label.CCOT-check', [m('input', { type: 'checkbox', checked: this.confirmDelete, disabled: this.busy, onchange: (event) => { this.confirmDelete = event.target.checked; } }), " " + t("lib.i_confirm_permanent_removal_of_ccot_data_without_recovery_of")]),
          m(Button, { className: 'Button Button--danger', loading: this.busy, disabled: this.busy || !this.confirmDelete || !(ready || purging), onclick: () => this.uninstall() }, purging ? t("lib.retry_uninstall") : t("lib.confirm_uninstall_and_remove_ccot_data")),
        ],
        !state && m(Button, { className: 'Button', onclick: () => this.load() }, t("lib.reload_account_status")),
      ]),
      this.notice && m('.Alert', { role: 'status' }, this.notice),
    ]);
  }
}
