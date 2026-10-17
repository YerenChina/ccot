import installPhotoComposer from './postImages';
import t from '../common/t';
import ConfirmActionModal from '../common/ConfirmActionModal';
import app from 'flarum/forum/app';
import Extend from 'flarum/common/extenders';
import { extend as extendMethod, override } from 'flarum/common/extend';
import HeaderSecondary from 'flarum/forum/components/HeaderSecondary';
import Notices from 'flarum/forum/components/Notices';
import Footer from 'flarum/forum/components/Footer';
import UserControls from 'flarum/forum/utils/UserControls';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import AuthForm from './AuthForm';
import { config, loadConfig, AuthPage, PolicyPage, TicketsPage } from './Pages';

app.initializers.add('bushcraftcn-ccot', () => {
  app.beforeMount(() => {
    if (!app.forum.attribute('ccotEnabled')) return;
    new Extend.Routes()
      .add('ccot.auth', '/ccot/account/:action', AuthPage)
      .add('ccot.policy', '/ccot/page/:page', PolicyPage)
      .add('ccot.tickets', '/ccot/tickets', TicketsPage)
      .extend(app);
    installPhotoComposer();
    loadConfig().then(() => m.redraw()).catch(() => {});
    extendMethod(HeaderSecondary.prototype, 'items', function (items) {
      if (app.session.user) return;
      if (app.forum.attribute('allowSignUp')) items.setContent('signUp', m(Link, { href: '/ccot/account/register', className: 'Button Button--link' }, t("lib.sign_up")));
      else items.remove('signUp');
      items.setContent('logIn', m(Link, { href: '/ccot/account/login', className: 'Button Button--link' }, t("lib.log_in")));
    });
    // Lazy-loaded native entry points use the same form, including login requested by the composer.
    for (const [name, action] of [['LogInModal', 'login'], ['SignUpModal', 'register']]) {
      override(`flarum/forum/components/${name}`, 'content', function () { return m('.Modal-body', m(AuthForm, { action, onready: (form) => { this.ccotForm = form; } })); });
      override(`flarum/forum/components/${name}`, 'onsubmit', function (_original, e) { this.ccotForm?.submit(e); });
    }
    extendMethod(Notices.prototype, 'items', function (items) {
      if (app.session.user?.attribute('ccotPhoneVerified')) items.remove('emailConfirmation');
      else if (app.session.user && app.forum.attribute('ccotShowLegacyEntry') !== false) items.add('ccotBindingRequired', m('.Alert.Alert--warning', [t("lib.verify_a_phone_number_for_your_existing_account_before_posting") + " ", m(Link, { href: '/ccot/account/bind' }, t("lib.verify_phone"))]));
    });
    extendMethod('flarum/forum/components/NotificationGrid', 'notificationMethods', function (items) {
      if (!this.attrs.user.email()) items.remove('email');
    });
    override(Footer.prototype, 'view', function (original) {
      return [original(), m('footer.CCOT-footer.container', [
        m('.CCOT-footer-row.CCOT-footer-links', [
          ...Object.entries({ agreement: t("lib.user_agreement"), privacy: t("lib.privacy_policy"), rules: t("lib.community_rules"), faq: t("lib.faq_and_regulatory_information") }).map(([key, text]) => m(Link, { href: `/ccot/page/${key}` }, text)),
          m(Link, { href: '/ccot/tickets' }, t("lib.complaints_and_contact")),
          m('a', { href: 'https://www.12377.cn/', target: '_blank', rel: 'noopener noreferrer' }, t("lib.internet_reporting_center")),
        ]),
        (config.icp || config.police) && m('.CCOT-footer-row.CCOT-footer-filings', [
          config.icp && m('a', { href: 'https://beian.miit.gov.cn/', target: '_blank', rel: 'noopener noreferrer' }, config.icp),
          config.police && m('a.CCOT-footer-police', { href: 'https://beian.mps.gov.cn/', target: '_blank', rel: 'noopener noreferrer' }, [m('span.CCOT-police-icon', { 'aria-hidden': 'true' }), config.police]),
        ]),
        config.organization && m('.CCOT-footer-row.CCOT-footer-organization', config.organization_url
          ? m('a', { href: config.organization_url, target: '_blank', rel: 'noopener noreferrer' }, config.organization)
          : m('span', config.organization)),
      ])];
    });
    extendMethod('flarum/forum/components/SettingsPage', 'accountItems', function (items) {
      items.remove('changeEmail'); items.remove('changePassword');
      const bound = app.session.user?.attribute('ccotPhoneVerified');
      items.add('ccotPhone', m(Link, { href: `/ccot/account/${bound ? 'change' : 'bind'}`, className: 'Button' }, bound ? t("lib.change_phone_masked", { phone: app.session.user.attribute('ccotPhoneMasked') }) : t("lib.bind_phone_number")));
      items.add('ccotPassword', m(Link, { href: '/ccot/account/reset', className: 'Button' }, t("lib.reset_password_by_sms")));
      items.add('ccotPrivacy', m(Link, { href: '/ccot/tickets', className: 'Button' }, t("lib.account_closure_and_personal_information_requests")));
    });
    extendMethod('flarum/forum/components/SettingsPage', 'privacyItems', function (items) {
      if (!('flarum-messages' in flarum.extensions)) return;
      items.add('ccotMessages', m('label', [m('input', { type: 'checkbox', checked: !!this.user.preferences().ccotRejectMessages, onchange: (e) => this.user.savePreferences({ ccotRejectMessages: e.target.checked }) }), " " + t("lib.reject_private_messages")]));
    });
    extendMethod('flarum/forum/components/UserPage', 'sidebarItems', function (items) {
      if (!this.user) return;
      items.add('ccotRegion', m('span', t("lib.region_label", { region: this.user.attribute('ccotRegion') || t("lib.unknown") })), -10);
      if ('flarum-messages' in flarum.extensions && app.session.user && app.session.user.id() !== this.user.id()) {
        const id = Number(this.user.id());
        const blocked = app.session.user.preferences().ccotBlockedUsers || [];
        items.add('ccotBlock', m(Button, { className: 'Button', onclick: () => app.session.user.savePreferences({ ccotBlockedUsers: blocked.includes(id) ? blocked.filter((x) => x !== id) : [...blocked, id] }) }, blocked.includes(id) ? t("lib.unblock_private_messages") : t("lib.block_private_messages_from_this_user")), -11);
      }
    });
    extendMethod(UserControls, 'moderationControls', function (items, user) {
      if (!app.session.user?.isAdmin() || !user.attribute('ccotPhoneVerified') || user.id() === '1') return;
      const blocked = !!user.attribute('ccotPhoneRestricted');
      items.add('ccotPhoneRestriction', m(Button, { className: 'Button', onclick: async () => {
        app.modal.show(ConfirmActionModal,{title:blocked?t('lib.remove_phone_restriction'):t('lib.serious_violation_restrict_phone'),message:blocked?t('lib.remove_login_interaction_and_registration_restrictions_for_this_number'):t('lib.restrict_this_number_for_180_days_for_a_serious_violation'),details:m('p',user.displayName()),run:async()=>{
        await app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/ccot/block`, body: { userId: Number(user.id()), blocked: !blocked, days: 180 } });
        await app.store.find('users', user.id());
        }});
      } }, blocked ? t("lib.remove_phone_restriction") : t("lib.serious_violation_restrict_phone")));
    });
  });
});
