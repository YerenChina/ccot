import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import t from '../common/t';

export default class DependencyWarnings extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.issues = null;
    this.busy = false;
    this.failed = false;
    this.retryable = true;
    this.removed = false;
    this.onFocus = () => this.load();
    this.onVisibility = () => { if (!document.hidden) this.load(); };
    window.addEventListener('focus', this.onFocus);
    document.addEventListener('visibilitychange', this.onVisibility);
    this.load();
  }

  async load() {
    if (this.busy || this.removed || document.hidden) return;
    clearTimeout(this.timer);
    this.busy = true;
    try {
      const result = await app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/ccot/dependencies` });
      if (!Array.isArray(result?.issues)) throw new Error('Invalid dependency status');
      if (!this.removed) {
        this.issues = result.issues; this.failed = false; this.retryable = true;
        this.attrs.onchange?.(this.issues.length === 0);
      }
    } catch (error) {
      if (!this.removed) {
        this.failed = true;
        this.attrs.onchange?.(false);
        this.retryable = ![401, 403, 404].includes(Number(error.status ?? error.response?.status));
      }
    } finally {
      if (!this.removed) {
        this.busy = false;
        if (this.retryable && (this.issues?.length || this.failed)) this.timer = setTimeout(() => this.load(), 15000);
        m.redraw();
      }
    }
  }

  onremove() {
    this.removed = true;
    clearTimeout(this.timer);
    window.removeEventListener('focus', this.onFocus);
    document.removeEventListener('visibilitychange', this.onVisibility);
  }

  view() {
    if (!this.failed && this.issues?.length === 0) return null;
    if (this.issues === null && !this.failed) return m('p.CCOT-help', { role: 'status' }, t('lib.dependencies_checking'));
    return m('section.Alert.Alert--error.CCOT-dependencies', { role: 'alert', 'aria-label': t('lib.dependencies_title') }, [
      m('strong', t('lib.dependencies_title')),
      m('p', t('lib.dependencies_required')),
      this.failed && m('p', t('lib.dependencies_failed')),
      this.issues?.length > 0 && m('ul', this.issues.map((issue) => m('li', { key: issue.id }, [
        m('strong', issue.label), '：', issue.detail, m('div', issue.action),
        issue.extensionId && app.data.extensions[issue.extensionId] && m(Link, { href: app.route('extension', { id: issue.extensionId }) }, t('lib.dependencies_open_extension')),
      ]))),
      m(Button, { type: 'button', className: 'Button', loading: this.busy, disabled: this.busy, onclick: () => this.load() }, t('lib.dependencies_recheck')),
    ]);
  }
}
