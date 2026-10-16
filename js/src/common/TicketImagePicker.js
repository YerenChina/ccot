import t from './t';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

export default class TicketImagePicker extends Component {
  oninit(vnode) { super.oninit(vnode); this.images = []; this.error = ''; }
  onremove() { this.images.forEach((image) => URL.revokeObjectURL(image.url)); }
  select(event) {
    const files = Array.from(event.target.files || []); event.target.value = ''; this.error = '';
    if (this.images.length + files.length > 3) this.error = t("lib.upload_at_most_3_images_per_ticket");
    else if (files.some((file) => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) this.error = t("lib.only_jpg_png_and_webp_images_are_supported");
    else if (files.some((file) => file.size <= 0 || file.size > 2 * 1024 * 1024)) this.error = t("lib.each_image_must_be_nonempty_and_no_larger_than_2");
    else this.images.push(...files.map((file) => ({ file, url: URL.createObjectURL(file) })));
    this.attrs.onchange(this.images.map((image) => image.file));
  }
  remove(image) {
    URL.revokeObjectURL(image.url); this.images = this.images.filter((item) => item !== image); this.error = '';
    this.attrs.onchange(this.images.map((item) => item.file));
  }
  view() {
    return m('.Form-group.CCOT-image-picker', [
      m('label', { for: 'ccot-ticket-images' }, t("lib.image_attachments_optional")),
      m('p.CCOT-help', t("lib.up_to_3_jpg_png_or_webp_images_each_no")),
      m('input.FormControl', { id: 'ccot-ticket-images', type: 'file', multiple: true, accept: 'image/jpeg,image/png,image/webp', disabled: this.attrs.disabled || this.images.length >= 3, onchange: (event) => this.select(event) }),
      this.error && m('.Alert', { role: 'alert' }, this.error),
      m('.CCOT-ticket-images', this.images.map((image, index) => m('figure', { key: image.url }, [m('img', { src: image.url, alt: t("lib.pending_image", { number: index + 1 }) }), m('figcaption', [m('span', t("lib.image_size", { number: index + 1, size: Math.ceil(image.file.size / 1024) })), m(Button, { type: 'button', className: 'Button Button--link', disabled: this.attrs.disabled, onclick: () => this.remove(image) }, t("lib.remove"))])]))),
    ]);
  }
}
