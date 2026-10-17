import ConfirmActionModal from '../common/ConfirmActionModal';
import t from '../common/t';

export default class PhotoActionModal extends ConfirmActionModal {
  options() {
    const image=this.attrs.image, block=this.attrs.action==='block';
    return {...this.attrs,
      title:t(block?'lib.post_images_take_down':'lib.post_images_delete'),
      message:t(block?'lib.post_images_confirm_down':'lib.post_images_delete_warning'),
      confirmLabel:t(block?'lib.post_images_take_down_confirm':'lib.post_images_delete_confirm'),
      failure:t('api.post_images_storage_failed'),
      details:[m('p',this.attrs.source),m('p',image.uploader?t('lib.post_images_uploader_value',{name:image.uploader.name,id:image.uploader.id}):t('lib.post_images_unknown_user')),m('code',image.id)],
    };
  }
}
