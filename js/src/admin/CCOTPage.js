import PostImages from './PostImages';
import t from '../common/t';
import ConfirmActionModal from '../common/ConfirmActionModal';
import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Link from 'flarum/common/components/Link';
import isExtensionEnabled from 'flarum/admin/utils/isExtensionEnabled';
import SmsSettings from './SmsSettings';
import AccountManagement from './AccountManagement';
import BackupPanel from './BackupPanel';
import TicketsPanel from '../common/TicketsPanel';
import DependencyWarnings from './DependencyWarnings';

const tabs = { get sms() { return t("lib.sms_settings"); }, get footer() { return t("lib.footer_and_pages"); }, get tickets() { return t("lib.tickets"); }, get photos() { return t("lib.post_images_photos"); }, get messages() { return t("lib.private_messages"); }, get region() { return t("lib.ip_region_settings"); }, get account() { return t("lib.account_management"); }, get uninstall() { return t("lib.uninstall_preparation"); }, get backup() { return t('lib.backup_title'); } };
const labels = {
  get organization() { return t("lib.operating_organization"); }, get organization_url() { return t("lib.organization_link_optional_complete_http_or_https_url"); }, get contact() { return t("lib.public_contact_email"); }, get icp() { return t("lib.icp_filing_number"); }, get police() { return t("lib.approved_public_security_filing_number"); },
  get agreement() { return t("lib.user_agreement"); }, get privacy() { return t("lib.privacy_policy"); }, get rules() { return t("lib.community_rules"); }, get faq() { return t("lib.faq_and_regulatory_information"); }, get sms_sign() { return t("lib.alibaba_cloud_sms_signature"); }, get sms_template() { return t("lib.verification_template_id"); }, get sms_daily_limit() { return t("lib.daily_site_sms_request_limit_including_failures"); },
};

export default class CCOTPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode); this.tab = 'sms'; this.health = null; this.dependenciesReady = false;
  }
  dependenciesChanged(ready) {
    if (this.dependenciesReady === ready) return;
    this.dependenciesReady = ready;
    if (ready) this.fetchHealth();
    else this.health = null;
  }
  async fetchHealth() {
    try {
      const health = await app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/ccot/health` });
      if (this.dependenciesReady) this.health = health;
    } catch (_) { this.health = null; }
    m.redraw();
  }
  infoItems() {
    const items = super.infoItems();
    items.remove('database-support');
    return items;
  }
  selectTab(tab) {
    if (tab !== this.tab && this.ticketPanel?.hasUnsaved()) {
      app.modal.show(ConfirmActionModal,{message:t('lib.there_are_unsaved_ticket_changes_leave_and_discard_them'),confirmLabel:t('lib.discard_changes'),run:()=>{this.tab=tab;}});
      return;
    }
    this.tab = tab;
  }
  field(key, multiline = false) {
    const value = this.setting(`bushcraftcn-ccot.${key}`, key === 'sms_daily_limit' ? '100' : '', labels[key]);
    return m('.Form-group', [m('label', { for: `ccot-setting-${key}` }, labels[key]), m(multiline ? 'textarea.FormControl' : 'input.FormControl', {
      id: `ccot-setting-${key}`, rows: multiline ? 8 : undefined, type: multiline ? undefined : key === 'sms_daily_limit' ? 'number' : key === 'organization_url' ? 'url' : 'text', value: value(), oninput: (e) => value(e.target.value),
    })]);
  }
  checkbox(key, label) {
    const value = this.setting(`bushcraftcn-ccot.${key}`, '0', label);
    return m('.Form-group', m('label.CCOT-check', [m('input', { type: 'checkbox', checked: value() === '1', onchange: (e) => value(e.target.checked ? '1' : '0') }), ' ', label]));
  }
  async toggleMessages(enabled) {
    this.changingMessages = true;
    try {
      await app.request({ method: 'PATCH', url: `${app.forum.attribute('apiUrl')}/extensions/flarum-messages`, body: { enabled } });
      window.location.reload();
    } catch (error) {
      this.changingMessages = false; m.redraw();
    }
  }
  content() {
    return m('.CCOT.CCOT-admin', [
      m(DependencyWarnings, { onchange: (ready) => this.dependenciesChanged(ready) }),
      this.dependenciesReady ? this.businessContent() : null,
    ]);
  }
  businessContent() {
    let body;
    if (this.tab === 'sms') {
      body = m(SmsSettings, { onsaved: () => this.fetchHealth() });
    } else if (this.tab === 'footer') {
      body = ['organization', 'organization_url', 'contact', 'icp', 'police', 'agreement', 'privacy', 'rules', 'faq'].map((key) => this.field(key, ['agreement', 'privacy', 'rules', 'faq'].includes(key)));
    } else if (this.tab === 'tickets') {
      body = [m('h2', t("lib.ticket_management")), m('p.CCOT-help', t("lib.expand_a_ticket_to_view_details_and_contact_information_reply")), m(TicketsPanel, { admin: true, onready: (panel) => { this.ticketPanel = panel; } })];
    } else if (this.tab === 'photos') {
      body = m(PostImages);
    } else if (this.tab === 'messages') {
      body = m('section.CCOT-messages', [m('.CCOT-message-toggle', app.data.extensions['flarum-messages']
        ? m(Switch, { state: isExtensionEnabled('flarum-messages'), loading: this.changingMessages, onchange: (enabled) => this.toggleMessages(enabled) }, t("lib.enable_official_messages"))
        : m('p', t("lib.official_messages_is_not_installed_private_messages_remain_disabled"))),
        m('p.CCOT-help', t("lib.disabling_hides_and_turns_off_private_messages_other_ccot_features")),
        m(Link, { href: app.route('extension', { id: 'flarum-messages' }) }, t("lib.open_official_messages_settings"))]);
    } else if (this.tab === 'account') {
      body = m('.CCOT-account-page', m(AccountManagement, { key: 'account', page: 'account' }));
    } else if (this.tab === 'uninstall') {
      body = m('.CCOT-account-page', m(AccountManagement, { key: 'uninstall', page: 'uninstall' }));
    } else if (this.tab === 'backup') {
      body = m(BackupPanel);
    } else {
      body = [m('p', t("lib.account_pages_show_a_chinese_province_or_an_overseas_country")), this.health && m('p', t("lib.region_status", { status: this.health.ipv6Ready ? t("lib.offline_database_configured") : t("lib.not_configured_unrecognized_addresses_show_unknown") }))];
    }
    return [m('p.CCOT-help', t('lib.admin_setup_help')), m('.CCOT-tabs', { role: 'tablist', 'aria-label': t("lib.ccot_administration_sections") }, Object.keys(tabs).map((tab) => m(Button, {
      type: 'button', role: 'tab', 'aria-selected': String(this.tab === tab), className: `Button ${this.tab === tab ? 'Button--primary' : ''}`, onclick: () => this.selectTab(tab),
    }, tabs[tab]))), body, this.tab === 'footer' && this.submitButton()];
  }
}
