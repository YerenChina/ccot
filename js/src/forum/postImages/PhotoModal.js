import Modal from 'flarum/common/components/Modal';
import t from '../../common/t';
export default class PhotoModal extends Modal {
  className() { return 'CCOT-photo-modal'; }
  title() { return t('lib.post_images_preview', { number: this.attrs.number || 1 }); }
  content() { return m('.Modal-body', [m('img', { src: this.attrs.image, alt: this.title(), onerror: () => { this.error = true; } }), this.error && m('p', { role: 'alert' }, t('lib.post_images_blocked'))]); }
}
