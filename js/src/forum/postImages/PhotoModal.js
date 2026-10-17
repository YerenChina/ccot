import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import t from '../../common/t';
export default class PhotoModal extends Modal {
  oninit(vnode) { super.oninit(vnode); this.index = this.attrs.index || 0; }
  current() { return this.attrs.images?.[this.index] || this.attrs; }
  move(step) { this.index += step; this.error = false; }
  className() { return 'CCOT-photo-modal'; }
  title() { return t('lib.post_images_preview', { number: this.attrs.images ? this.index + 1 : this.attrs.number || 1 }); }
  content() {
    const image = this.current();
    return m('.Modal-body', [
      !this.error && m('.CCOT-photo-content', [m('img', {key:image.image,src:image.image,alt:this.title(),onerror:()=>{this.error=true;m.redraw();}})]),
      this.error && m('p', {role:'alert'}, t('lib.post_images_blocked')),
      image.caption && m('p.CCOT-help', image.caption),
      this.attrs.images && m('.CCOT-photo-navigation', [
        m(Button,{type:'button',className:'Button',disabled:this.index===0,onclick:()=>this.move(-1)},t('lib.post_images_previous')),
        m('span',t('lib.post_images_preview_position',{current:this.index+1,total:this.attrs.images.length})),
        m(Button,{type:'button',className:'Button',disabled:this.index+1===this.attrs.images.length,onclick:()=>this.move(1)},t('lib.post_images_next')),
      ]),
    ]);
  }
}
