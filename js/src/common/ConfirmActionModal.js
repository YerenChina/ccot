import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import t from './t';

export default class ConfirmActionModal extends Modal {
  className() { return 'CCOT-confirm-action-modal'; }
  options() { return this.attrs; }
  title() { return this.options().title || t('lib.confirm_action'); }
  hide() { if (!this.loading) super.hide(); }
  async submit() {
    if (this.loading) return;
    this.loading = true; this.error = '';
    try { await this.attrs.run(); this.loading = false; this.hide(); }
    catch (error) { this.error = error?.response?.errors?.[0]?.detail || this.options().failure || t('lib.the_operation_failed_please_retry_later'); }
    finally { this.loading = false; m.redraw(); }
  }
  content() {
    const options=this.options();
    return m('.Modal-body', [
      m('p', options.message), options.details,
      this.error && m('p.Alert.Alert--error', {role:'alert'}, this.error),
      m('.CCOT-links', [
        m(Button, {type:'button',className:'Button '+(options.danger===false?'Button--primary':'Button--danger'),loading:this.loading,disabled:this.loading,onclick:()=>this.submit()}, options.confirmLabel || t('lib.confirm_action')),
        m(Button, {type:'button',className:'Button',disabled:this.loading,onclick:()=>this.hide()}, t('lib.post_images_cancel')),
      ]),
    ]);
  }
}
