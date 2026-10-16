import t from '../common/t';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';

export default class AuthForm extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.action = this.attrs.action || 'login';
    this.mode = 'password';
    this.values = { phone: '', nickname: '', identification: '', password: '', code: '', oldPhone: '', oldCode: '', adult: false, agreement: false };
    this.challenge = '';
    this.oldChallenge = '';
    this.busy = false;
    this.notice = '';
    this.cooldowns = {};
    if (this.attrs.onready) this.attrs.onready(this);
  }

  input(name, label, type = 'text', attrs = {}) {
    return m('.Form-group', [m('label', { for: `ccot-${name}` }, label), m('input.FormControl', {
      id: `ccot-${name}`, name, type, value: this.values[name], required: true, ...attrs,
      oninput: (e) => { this.values[name] = e.target.value; if (name === 'phone') this.challenge = ''; if (name === 'oldPhone') this.oldChallenge = ''; },
    })]);
  }

  async send(old = false) {
    if (this.busy || (this.action === 'register' && !this.canRegister())) return;
    this.busy = true;
    this.notice = '';
    const phone = old ? this.values.oldPhone : this.values.phone;
    const purpose = old ? 'old' : this.action === 'change' ? 'new' : this.action;
    try {
      const result = await app.request({ method: 'POST', url: `${app.forum.attribute('baseUrl')}/ccot/auth/code`, body: { phone, purpose } });
      if (old) this.oldChallenge = result.challenge; else this.challenge = result.challenge;
      this.cooldowns[old ? 'old' : 'new'] = Date.now() + 60000;
      this.notice = result.message;
      const timer = setTimeout(() => { delete this.cooldowns[old ? 'old' : 'new']; m.redraw(); }, 60000);
      (this.timers ||= []).push(timer);
    } catch (e) { this.notice = e.response?.errors?.[0]?.detail || t("lib.send_failed_please_retry_later"); }
    finally { this.busy = false; m.redraw(); }
  }

  async submit(e) {
    e?.preventDefault();
    if (this.busy || (this.action === 'register' && !this.canRegister())) return;
    this.busy = true;
    this.notice = '';
    try {
      await app.request({ method: 'POST', url: `${app.forum.attribute('baseUrl')}${['admin', 'legacy'].includes(this.action) ? '/login' : '/ccot/auth/' + this.action}`, body: {
        ...this.values, mode: this.mode, challenge: this.challenge, oldChallenge: this.oldChallenge,
      } });
      // Clear sensitive inputs before leaving the form.
      this.values.password = this.values.code = this.values.oldCode = this.values.phone = this.values.oldPhone = '';
      window.location.assign(app.forum.attribute('baseUrl') + (this.action === 'legacy' ? '/ccot/account/bind' : ['bind', 'change'].includes(this.action) ? '/settings' : '/'));
    } catch (e) { this.notice = e.response?.errors?.[0]?.detail || t("lib.the_operation_failed_check_your_input"); }
    finally { this.busy = false; m.redraw(); }
  }

  onremove() { (this.timers || []).forEach(clearTimeout); }

  canRegister() { return app.forum.attribute('allowSignUp') !== false; }
  showLegacyEntry() { return app.forum.attribute('ccotShowLegacyEntry') !== false; }

  links() {
    return m('.CCOT-links', [
      ...Object.entries({ login: t("lib.phone_login"), legacy: t("lib.verify_phone_for_an_existing_account"), register: t("lib.sign_up"), reset: t("lib.forgot_password"), admin: t("lib.administrator_login") })
        .filter(([key]) => key !== this.action && (key !== 'register' || this.canRegister()) && (key !== 'legacy' || this.showLegacyEntry()))
        .map(([key, text]) => m(Button, { type: 'button', className: 'Button Button--link', onclick: () => {
          this.action = key; this.challenge = ''; this.oldChallenge = ''; this.notice = '';
          this.values.password = this.values.code = this.values.oldCode = '';
        } }, text)),
    ]);
  }

  code(old = false) {
    const key = old ? 'old' : 'new';
    return m('.Form-group.CCOT-code', [
      this.input(old ? 'oldCode' : 'code', old ? t("lib.code_for_current_phone") : t("lib.sms_code"), 'text', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 6 }),
      m(Button, { type: 'button', className: 'Button', disabled: this.busy || !!this.cooldowns[key], onclick: () => this.send(old) }, this.cooldowns[key] ? t("lib.resend_later") : t("lib.get_code")),
    ]);
  }

  view() {
    const titles = { register: t("lib.sign_up"), login: t("lib.log_in"), reset: t("lib.reset_password"), bind: t("lib.bind_phone_number"), change: t("lib.change_phone_number"), admin: t("lib.administrator_login"), legacy: t("lib.verify_phone_for_an_existing_account") };
    if (!titles[this.action]) return m('.CCOT-auth', [m('h3', t("lib.page_not_found")), this.links()]);
    if (this.action === 'register' && !this.canRegister()) return m('.CCOT-auth', [m('h3', t("lib.registration_paused")), m('p', this.showLegacyEntry() ? t("lib.existing_accounts_can_still_log_in_and_complete_phone_verification") : t("lib.existing_accounts_can_still_log_in")), this.links()]);
    if (['bind', 'change'].includes(this.action) && !app.session.user) return m('.CCOT-auth', [m('h3', t("lib.log_in_to_your_existing_account_first")), this.links()]);
    if (this.action === 'bind' && app.session.user?.attribute('ccotPhoneVerified')) return m('.CCOT-auth', [m('h3', t("lib.phone_verified")), m(Link, { href: '/settings', className: 'Button' }, t("lib.return_to_account_settings"))]);
    const sms = this.action !== 'login' || this.mode === 'sms';
    const native = ['admin', 'legacy'].includes(this.action);
    // Both the standalone page and native login modal already supply a form.
    return m('.CCOT-auth', [
      m('h3', titles[this.action]),
      this.action === 'legacy' && m('p.CCOT-help', t("lib.use_your_original_username_or_email_and_password_then_verify")),
      this.action === 'login' && m('.CCOT-tabs', ['password', 'sms'].map((mode) => m(Button, {
        className: `Button ${this.mode === mode ? 'Button--primary' : ''}`, type: 'button', onclick: () => { this.mode = mode; this.notice = ''; },
      }, mode === 'password' ? t("lib.password_login") : t("lib.code_login")))),
      this.action === 'register' && this.input('nickname', t("lib.nickname_chinese_supported_must_be_unique"), 'text', { autocomplete: 'nickname', maxlength: 150 }),
      this.action === 'change' && [this.input('oldPhone', t("lib.current_phone_number"), 'tel', { inputmode: 'tel', maxlength: 11 }), this.code(true)],
      native ? this.input('identification', this.action === 'legacy' ? t("lib.original_username_or_email") : t("lib.administrator_username_or_email"), 'text', { autocomplete: 'username', maxlength: 150 }) : this.input('phone', this.action === 'change' ? t("lib.new_phone_number") : t("lib.phone_number"), 'tel', { autocomplete: 'tel-national', maxlength: 11, inputmode: 'tel' }),
      sms && !native && this.code(),
      (['register', 'reset', 'admin', 'legacy'].includes(this.action) || !sms) && this.input('password', this.action === 'reset' ? t("lib.new_password") : t("lib.password"), 'password', { autocomplete: this.action === 'login' || native ? 'current-password' : 'new-password', minlength: native || this.action === 'login' ? 1 : 8, maxlength: 256 }),
      this.action === 'register' && [
        m('label.CCOT-check', [m('input', { type: 'checkbox', checked: this.values.adult, onchange: (e) => { this.values.adult = e.target.checked; } }), " " + t("lib.i_am_at_least_18_years_old")]),
        m('label.CCOT-check', [m('input', { type: 'checkbox', checked: this.values.agreement, onchange: (e) => { this.values.agreement = e.target.checked; } }), " " + t("lib.i_have_read_and_agree_to") + " ", m(Link, { href: '/ccot/page/agreement' }, t("lib.user_agreement")), t("lib.and_have_read") + " ", m(Link, { href: '/ccot/page/privacy' }, t("lib.privacy_policy"))]),
      ],
      this.notice && m('p.CCOT-notice', { role: 'status' }, this.notice),
      m(Button, { type: 'submit', className: 'Button Button--primary', loading: this.busy, disabled: this.busy }, this.action === 'legacy' ? t("lib.log_in_and_verify_phone") : titles[this.action]),
      ['login', 'register', 'reset', 'admin', 'legacy'].includes(this.action) && this.links(),
    ]);
  }
}
