import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import PhotoModal from './PhotoModal';
import t from '../../common/t';
export default class Picker extends Component {
  view() {
    const state = this.attrs.state, options = app.forum.attribute('ccotPostImages');
    if (!state || app.session.user?.id() !== state.owner) return null;
    const disabled = !!this.attrs.disabled;
    return m('section.CCOT-post-picker', { 'aria-label': t('lib.post_images_photos') }, [
      !state.simple && m('p.CCOT-help', t('lib.post_images_native')),
      m('.CCOT-post-thumbnails', state.items.map((item,index) => m('figure', { key: item.key }, [
        item.thumb ? m('button.CCOT-photo-preview', { type:'button', 'aria-label':t('lib.post_images_preview',{number:index+1}), onclick:()=>app.modal.show(PhotoModal,{image:item.image,number:index+1}) }, m('img',{src:item.thumb,alt:t('lib.post_images_preview',{number:index+1}),onerror:event=>{event.target.alt=t('lib.post_images_blocked');}})) : m('.CCOT-photo-pending', { role:'status' }, item.status === 'failed' ? t('lib.post_images_failed') : t('lib.post_images_uploading',{progress:item.progress})),
        m('figcaption', [item.status === 'failed' && m(Button,{type:'button',className:'Button Button--link',disabled,onclick:()=>state.retry(item)},t('lib.post_images_retry')),
          (state.simple || !item.existing) && m(Button,{type:'button',className:'Button Button--link',disabled:disabled||item.status==='uploading',onclick:()=>state.remove(item)},t('lib.post_images_remove'))]),
      ]))),
      options.enabled && app.session.user?.attribute('ccotPhoneVerified') && m('label.Button.CCOT-add-photo', [m('i.fas.fa-image',{'aria-hidden':'true'}),' ',t('lib.post_images_add'),m('input',{type:'file',multiple:true,accept:'image/jpeg,image/png,image/webp',disabled:disabled||state.items.length>=options.max_count,onchange:event=>{state.select(Array.from(event.target.files||[]));event.target.value='';}})]),
      m('p.CCOT-help',t('lib.post_images_limits',{size:options.max_mb,pixels:options.max_mp})),
      state.error && m('p.Alert.Alert--error',{role:'alert'},state.error),
    ]);
  }
}
