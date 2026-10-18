import t from '../common/t';
import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

export default class SmsSettings extends Component {
  oninit(vnode) {
    super.oninit(vnode); this.values = null; this.keyId = ''; this.keySecret = ''; this.phone = ''; this.code = ''; this.notice = ''; this.dirty = false; this.testDirty = false; this.testLimit = null;
    this.load();
  }
  onremove() { this.removed = true; clearInterval(this.cooldownTimer); }
  updateCooldown(seconds) {
    clearInterval(this.cooldownTimer);
    if (this.removed) return;
    this.cooldownUntil = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
    if (this.waitSeconds()) this.cooldownTimer = setInterval(() => {
      if (!this.waitSeconds()) clearInterval(this.cooldownTimer);
      m.redraw();
    }, 1000);
  }
  waitSeconds() { return Math.max(0, Math.ceil(((this.cooldownUntil || 0) - Date.now()) / 1000)); }
  testBlockReason() {
    if (this.dirty) return t("lib.save_your_changed_sms_settings_before_sending_a_test_code");
    if (this.testDirty) return t('lib.save_test_settings_first');
    if (!this.values?.test_ready) return this.values.test_block_reason || t("lib.save_complete_sms_settings_and_a_recipient_phone_number_first");
    if (this.waitSeconds()) return t("lib.send_wait_help", { seconds: this.waitSeconds() });
    return '';
  }
  async load() {
    try {
      const values = await app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/ccot/sms-settings` });
      if(this.removed)return;
      this.values=values; this.testLimit ??= values.test_limit;
      this.updateCooldown(this.values.test_retry_after);
    }
    catch { this.notice ||= t("lib.could_not_read_sms_settings_check_the_private_directory_and"); }
    m.redraw();
  }
  field(key, label, type = 'text', extra = {}) {
    return m('.Form-group', [m('label', { for: `ccot-sms-${key}` }, label), m('input.FormControl', {
      id: `ccot-sms-${key}`, type, value: this.values[key], oninput: (event) => { this.values[key] = event.target.value; this.dirty = true; }, ...extra,
    })]);
  }
  check(key, label) {
    return m('label.CCOT-check', [m('input', { type: 'checkbox', checked: !!this.values[key], onchange: (event) => { this.values[key] = event.target.checked; this.dirty = true; } }), ' ', label]);
  }
  async save() {
    if(this.busy)return;
    this.busy = true; this.notice = '';
    try {
      this.values = await app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/ccot/sms-settings`, body: {
        ...Object.fromEntries(['sign','template','variable','site_limit','interval','phone_limit','ip_limit','enabled','paused','clear_keys'].map(key=>[key,this.values[key]])), key_id:this.keyId, key_secret:this.keySecret,
      } });
      this.keyId = this.keySecret = ''; this.dirty = false; this.notice = t("lib.saved_the_accesskey_and_recipient_phone_number_are_not_displayed");
      this.updateCooldown(this.values.test_retry_after);
      this.attrs.onsaved?.();
    } catch (error) { this.notice = error.response?.errors?.[0]?.detail || t("lib.save_failed"); }
    finally { this.busy = false; m.redraw(); }
  }
  async startTest() {
    if(this.busy)return;
    if(this.dirty){this.notice=t('lib.save_your_changed_sms_settings_before_sending_a_test_code');return;}
    this.busy=true;this.notice='';
    try {
      this.values=await app.request({method:'POST',url:`${app.forum.attribute('apiUrl')}/ccot/sms-test-start`,body:{test_phone:this.phone,test_limit:this.testLimit??this.values.test_limit}});
      this.phone='';this.testLimit=this.values.test_limit;this.testDirty=false;this.challenge='';this.code='';
      this.notice=t('lib.test_started');this.updateCooldown(this.values.test_retry_after);
    } catch(error){this.notice=error.response?.errors?.[0]?.detail||t('lib.save_failed');}
    finally{this.busy=false;m.redraw();}
  }
  async test() {
    if(this.busy)return;
    const reason=this.testBlockReason();if(reason){this.notice=reason;return;}
    this.busy = true; this.notice = t("lib.submitting_the_send_request_please_wait_for_the_result"); m.redraw();
    try {
      const result = await app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/ccot/sms-test` });
      this.challenge = result.challenge; this.notice = result.message;
    } catch (error) {
      const failure = error.response?.errors?.[0];
      this.notice = t("lib.send_failure_detail", { detail: failure?.detail || t("lib.the_request_failed_check_the_network_and_retry"), provider: failure?.providerCode ? '（'+failure.providerCode+'）' : '' });
      if (failure?.retryAfter) this.updateCooldown(failure.retryAfter);
    }
    finally { await this.load(); this.busy = false; m.redraw(); }
  }
  async verify() {
    this.busy = true;
    try {
      const result = await app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/ccot/sms-test-verify`, body: { challenge: this.challenge, code: this.code } });
      this.notice = result.message; this.code = ''; this.challenge = '';
    } catch (error) { this.notice = error.response?.errors?.[0]?.detail || t("lib.verification_failed"); }
    finally { this.busy = false; m.redraw(); }
  }
  view() {
    if (!this.values) return m('p', this.notice || t("lib.loading_sms_settings"));
    return m('div', [
      m('p', t("lib.sms_provider_alibaba_cloud_enabling_sends_real_messages_charged_by")),
      m('p', t("lib.key_help", { masked: this.values.key_id_masked })),
      m('.Form-group', [m('label', { for: 'ccot-sms-key-id' }, 'AccessKey ID'), m('input.FormControl', { id: 'ccot-sms-key-id', type: 'password', autocomplete: 'new-password', value: this.keyId, oninput: (e) => { this.keyId = e.target.value; this.dirty = true; } })]),
      m('.Form-group', [m('label', { for: 'ccot-sms-key-secret' }, 'AccessKey Secret'), m('input.FormControl', { id: 'ccot-sms-key-secret', type: 'password', autocomplete: 'new-password', value: this.keySecret, oninput: (e) => { this.keySecret = e.target.value; this.dirty = true; } })]),
      this.field('sign', t("lib.approved_sms_signature")), this.field('template', t("lib.approved_template_id")), this.field('variable', t("lib.code_variable_name_default_code_one_variable_only")),
      this.field('site_limit', t("lib.daily_site_send_request_limit"), 'number', { min: 1, max: 10000 }),
      m('details.CCOT-sms-limits', [m('summary', t("lib.sending_frequency_and_phone_ip_limits")), this.field('interval', t("lib.interval_per_phone_number_seconds"), 'number', { min: 10, max: 3600 }), this.field('phone_limit', t("lib.daily_limit_per_phone_number"), 'number', { min: 1, max: 100 }), this.field('ip_limit', t("lib.hourly_limit_per_ip_address"), 'number', { min: 1, max: 1000 }),
        m('p.CCOT-help', t("lib.suggested_limits_60_seconds_between_sends_10_per_phone_per"))]),
      this.check('enabled', t("lib.allow_real_sms_sending_alibaba_cloud_charges_apply")), this.check('paused', t("lib.pause_sending")), this.check('clear_keys', t("lib.clear_saved_accesskey_and_revoke_real_sending_permission")),
      m(Button,{type:'button',className:'Button Button--primary',loading:this.busy,disabled:this.busy,onclick:()=>this.save()},t('lib.save_sms_settings')),
      m('h3', t("lib.sms_sending_test_real_sms")),
      m('p', t("lib.check_alibaba_cloud_configuration_and_phone_delivery_sending_a_test")),
      m('.Form-group', [m('label', { for: 'ccot-sms-test-phone' }, t("lib.test_recipient", { current: this.values.test_phone_masked ? t("lib.current") + " "+this.values.test_phone_masked+t("lib.leave_blank_to_keep") : '' })), m('input.FormControl', { id: 'ccot-sms-test-phone', type: 'tel', maxlength: 11, autocomplete: 'off', value: this.phone, oninput: (e) => { this.phone = e.target.value; this.testDirty = true; } })]),
      m('.Form-group',[m('label',{for:'ccot-sms-test-limit'},t('lib.request_limit_for_this_test_suggested_3')),m('input.FormControl',{id:'ccot-sms-test-limit',type:'number',min:1,max:20,value:this.testLimit??this.values.test_limit,oninput:e=>{this.testLimit=e.target.value;this.testDirty=true;}})]),
      m('p.CCOT-help',t('lib.test_budget',{used:this.values.test_used,limit:this.values.test_limit})),
      m('p.CCOT-help',t('lib.sms_test_separate_limits')),
      m('.CCOT-sms-test-status', { role: 'status', 'aria-live': 'polite' }, [
        this.notice && m('.Alert', this.notice),
        this.testBlockReason() && m('p', this.testBlockReason()),
      ]),
      m('.CCOT-tabs', [m(Button, { type: 'button', className: 'Button', disabled: this.busy || this.dirty || !this.values.enabled, onclick: () => this.startTest() }, this.values.grant_id?t('lib.restart_this_test'):t('lib.start_this_test')), m(Button, { type: 'button', className: 'Button', loading: this.busy, disabled: this.busy || !!this.testBlockReason(), onclick: () => this.test() }, this.waitSeconds() ? t('lib.wait_seconds', { seconds: this.waitSeconds() }) : t('lib.send_test_code'))]),
      this.challenge && m('.Form-group', [m('label', { for: 'ccot-sms-test-code' }, t("lib.test_code")), m('input.FormControl', { id: 'ccot-sms-test-code', maxlength: 6, inputmode: 'numeric', autocomplete: 'one-time-code', value: this.code, oninput: (e) => { this.code = e.target.value; } }), m(Button, { type: 'button', className: 'Button', disabled: this.busy, onclick: () => this.verify() }, t("lib.verify_test_code"))]),
    ]);
  }
}
