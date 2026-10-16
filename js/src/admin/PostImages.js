import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import t from '../common/t';
const labels={enabled:'lib.post_images_enable',simple:'lib.post_images_simple',max_mb:'lib.post_images_max_mb',max_mp:'lib.post_images_max_mp',max_count:'lib.post_images_max_count',capacity_gb:'lib.post_images_capacity_gb'};
export default class PostImages extends Component {
  oninit(vnode) { super.oninit(vnode); this.values=null;this.images=[];this.candidates=[];this.error='';this.manual='';this.load(); }
  async load() { this.error='';try { const [values,data]=await Promise.all([app.request({url:app.forum.attribute('apiUrl')+'/ccot/post-images/settings'}),app.request({url:app.forum.attribute('apiUrl')+'/ccot/post-images/manage'})]);this.values=values;this.images=data.images;this.candidates=data.candidates;this.storage=data.storage; } catch(error) {this.error=error?.response?.errors?.[0]?.detail||t('api.post_images_storage_failed');} m.redraw(); }
  async save() {if(this.saving)return;this.saving=true;this.message='';this.error='';try{this.values=await app.request({method:'POST',url:app.forum.attribute('apiUrl')+'/ccot/post-images/settings',body:this.values});this.message=t('lib.post_images_saved');}catch(error){this.error=error?.response?.errors?.[0]?.detail||t('api.post_images_storage_failed');}finally{this.saving=false;m.redraw();}}
  async block(id) { if(this.blocking||!window.confirm(t('lib.post_images_confirm_down')))return;this.blocking=true;try{await app.request({method:'POST',url:app.forum.attribute('apiUrl')+'/ccot/post-images/'+id+'/block'});this.manual='';await this.load();}catch(error){this.error=error?.response?.errors?.[0]?.detail||t('api.post_images_storage_failed');}finally{this.blocking=false;m.redraw();} }
  view() {
    if(!this.values)return m('p',{role:'status'},this.error||t('lib.post_images_loading'));
    return m('section.CCOT-post-settings',[
      m('h2',t('lib.post_images_photos')),
      ...['enabled','simple'].map(key=>m('.Form-group',m(Switch,{state:this.values[key],onchange:value=>{this.values[key]=value;}},t(labels[key])))),
      ...['max_mb','max_mp','max_count','capacity_gb'].map(key=>m('.Form-group',[m('label',{for:'ccot-photo-'+key},t(labels[key])),m('input.FormControl',{id:'ccot-photo-'+key,type:'number',min:1,max:{max_mb:16,max_mp:40,max_count:9,capacity_gb:64}[key],value:this.values[key],oninput:e=>{this.values[key]=e.target.value;}})])),
      m('p.CCOT-help',t('lib.post_images_frequency')),m('p.CCOT-help',t('lib.post_images_preserve')),
      m(Button,{type:'button',className:'Button Button--primary',loading:this.saving,onclick:()=>this.save()},t('lib.post_images_save')),
      this.message&&m('p',{role:'status'},this.message),this.error&&m('p.Alert',{role:'alert'},this.error),
      m('p.CCOT-help',t('lib.post_images_storage',{path:this.storage})),m('p.CCOT-help',t('lib.post_images_cleanup')),m('p',t('lib.post_images_clean_candidates',{count:this.candidates.length})),
      m('h3',t('lib.post_images_moderation')),
      m('.Form-group',[m('label',{for:'ccot-photo-manual'},'ID'),m('input.FormControl',{id:'ccot-photo-manual',value:this.manual,oninput:e=>{this.manual=e.target.value.trim();},maxlength:32}),m(Button,{type:'button',className:'Button',disabled:this.blocking||!/^[a-f0-9]{32}$/.test(this.manual),onclick:()=>this.block(this.manual)},t('lib.post_images_take_down'))]),
      m(Button,{type:'button',className:'Button',onclick:()=>this.load()},t('lib.post_images_refresh')),
      m('ul.CCOT-photo-management',this.images.map(image=>m('li',{key:image.id},[
        !image.blocked&&m('a',{href:image.image,target:'_blank',rel:'noopener noreferrer'},m('img',{src:image.thumb,alt:'',loading:'lazy'})),
        m('div',[m('code',image.id),m('p',image.postId?m('a',{href:image.postUrl,target:'_blank',rel:'noopener noreferrer'},t('lib.post_images_post',{id:image.postId})):t('lib.post_images_pending')),m('span',image.width+'×'+image.height+' · '+Math.ceil(image.bytes/1024)+'KiB')]),
        image.blocked?m('span',t('lib.post_images_blocked')):m(Button,{type:'button',className:'Button',disabled:this.blocking,onclick:()=>this.block(image.id)},t('lib.post_images_take_down')),
      ]))),
    ]);
  }
}
