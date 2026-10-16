import app from 'flarum/forum/app';
import ItemList from 'flarum/common/utils/ItemList';
import { extend, override } from 'flarum/common/extend';
import Picker from './Picker';
import PhotoState from './State';
import PhotoModal from './PhotoModal';
import t from '../../common/t';
function initialize(component) {
  const owner = app.session.user?.id(), post = component.attrs.post, config = app.forum.attribute('ccotPostImages');
  const prior = component.composer.fields.ccotPhotos;
  if (prior && prior.owner !== owner) {
    component.composer.fields.content(''); component.composer.fields.title?.('');
    component.composer.fields.ccotPhotos = null; component.ccotPhotos = null;
    return; // Never initialize another account's editor from an old post model/draft.
  }
  if (!owner || !config) return;
  const editor = post?.attribute('ccotImageEditor'), simple = config.simple && (!post || editor?.simple === true);
  const context = post ? {post_id:post.id()} : component.attrs.discussion ? {discussion_id:component.attrs.discussion.id()} : {};
  const key = post ? 'post:'+post.id() : component.attrs.discussion ? 'reply:'+component.attrs.discussion.id() : 'discussion';
  const state = prior && prior.owner === owner && prior.composerKey === key ? prior : new PhotoState(owner,post?.attribute('ccotPostImages')||[],context,simple,component.composer);
  state.composerKey = key; component.composer.fields.ccotPhotos = state; component.ccotPhotos = state;
  if (post && simple && prior !== state) { state.originalText = editor.text; component.composer.fields.content(editor.text); }
}
export default function installPhotoComposer() {
  for (const name of ['DiscussionComposer','ReplyComposer','EditPostComposer']) {
    const target = 'flarum/forum/components/'+name;
    extend(target,'oninit',function(){initialize(this);});
    extend(target,'data',function(data){if(this.ccotPhotos)data.content=this.ccotPhotos.compose(data.content);});
    override(target,'hasChanges',function(original){
      if(this.attrs.post && this.ccotPhotos?.simple)return this.composer.fields.content()!==this.ccotPhotos.originalText || this.ccotPhotos.changed();
      return original() || !!this.ccotPhotos?.changed();
    });
    override(target,'onsubmit',function(original,...args){if(this.loading)return;if(this.ccotPhotos?.pending()){this.ccotPhotos.error=t('lib.post_images_wait');m.redraw();return;}return original(...args);});
  }
  extend('flarum/common/components/TextEditor','view',function(vnode){const state=this.attrs.composer?.fields.ccotPhotos;if(!state)return;const queue=[vnode];while(queue.length){const parent=queue.shift();const children=parent?.children;if(!Array.isArray(children))continue;const at=children.findIndex(child=>child?.tag==='ul'&&child.attrs?.className?.includes('TextEditor-controls'));if(at!==-1){children.splice(at,0,m(Picker,{state,disabled:this.attrs.disabled}));return;}queue.push(...children.filter(child=>child&&typeof child==='object'));}});
  override('flarum/common/components/TextEditor','toolbarItems',function(original){const state=this.attrs.composer?.fields.ccotPhotos;return state?.simple?new ItemList():original();});
  extend('flarum/common/components/TextEditor','controlItems',function(items){const state=this.attrs.composer?.fields.ccotPhotos;if(!state)return;const submit=items.get('submit');if(submit?.attrs)submit.attrs.disabled=!!this.attrs.disabled||state.pending();});
  for(const method of ['oncreate','onupdate'])extend('flarum/forum/components/CommentPost',method,function(){
    const images=this.attrs.post.attribute('ccotPostImages')||[];
    for(const image of images){for(const node of this.element.querySelectorAll('.Post-body img')){if(node.getAttribute('src')!==image.thumb)continue;const anchor=node.closest('a');if(!anchor)continue;anchor.classList.add('CCOT-post-image');anchor.onclick=event=>{event.preventDefault();app.modal.show(PhotoModal,{image:image.image});};node.onerror=()=>{node.alt=t('lib.post_images_blocked');node.classList.add('CCOT-image-unavailable');};}}
    // Only an image-only native paragraph becomes a gallery. Historical inline content stays in place.
    for(const paragraph of this.element.querySelectorAll('.Post-body p')){
      if(paragraph.querySelector('.CCOT-post-image')&&Array.from(paragraph.childNodes).every(node=>node.nodeType===3?!node.textContent.trim():node.nodeName==='BR'||node.classList?.contains('CCOT-post-image')))paragraph.classList.add('CCOT-post-gallery');
    }
  });
}
