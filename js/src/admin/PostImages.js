import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import PhotoModal from '../forum/postImages/PhotoModal';
import PhotoActionModal from './PhotoActionModal';
import t from '../common/t';

const labels={enabled:'lib.post_images_enable',simple:'lib.post_images_simple',max_mb:'lib.post_images_max_mb',max_mp:'lib.post_images_max_mp',max_count:'lib.post_images_max_count',capacity_gb:'lib.post_images_capacity_gb'};
const stateLabels={all:'lib.post_images_state_all',published:'lib.post_images_state_published',pending:'lib.post_images_state_pending',unused:'lib.post_images_state_unused',blocked:'lib.post_images_state_blocked',deleted:'lib.post_images_state_deleted'};
const emptyFilters=()=>({discussion:'',user:'',image:'',state:'all',perPage:20});

export default class PostImages extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.values=null; this.images=[]; this.error='';
    this.filters=emptyFilters(); this.query=emptyFilters();
    this.pagination={page:1,perPage:20,total:0,pages:1}; this.pageInput=1;
    this.load();
  }
  api(path) { return app.forum.attribute('apiUrl')+'/ccot/post-images'+path; }
  async load(page=this.pagination?.page||1) {
    if(this.saving||this.loading)return;
    this.loading=true; this.error='';
    try {
      const query=this.query||emptyFilters();
      const params=new URLSearchParams({...query,page}).toString();
      const [values,data]=await Promise.all([
        this.values ? Promise.resolve(this.values) : app.request({url:this.api('/settings')}),
        app.request({url:this.api('/manage')+'?'+params}),
      ]);
      if(!this.removed){this.values=values;this.images=data.images;this.pagination=data.pagination;this.pageInput=data.pagination.page;this.storage=data.storage;this.cleanupError=data.cleanupError;}
    } catch(error) {if(!this.removed)this.error=error?.response?.errors?.[0]?.detail||t('api.post_images_storage_failed');}
    finally {if(!this.removed){this.loading=false;m.redraw();}}
  }
  applyFilters(reset=false) {
    if(this.saving||this.loading)return;
    if(reset)this.filters=emptyFilters();
    this.query={...this.filters};return this.load(1);
  }
  async save() {
    if(this.saving||this.loading)return;
    this.saving=true;this.message='';this.error='';
    const body=Object.fromEntries(['max_mb','max_mp','max_count','capacity_gb'].map(key=>[key,this.values[key]]));
    try{const values=await app.request({method:'POST',url:this.api('/settings'),body});if(!this.removed){this.values=values;this.message=t('lib.post_images_saved');}}
    catch(error){if(!this.removed)this.error=error?.response?.errors?.[0]?.detail||t('api.post_images_storage_failed');}
    finally{if(!this.removed){this.saving=false;m.redraw();}}
  }
  async toggle(key,value) {
    if(this.saving||this.loading)return;
    const previous=this.values[key];this.values[key]=value;this.saving=true;this.message='';this.error='';
    try{const result=await app.request({method:'PATCH',url:this.api('/settings'),body:{[key]:value}});if(!this.removed){this.values[key]=result[key];this.message=t('lib.post_images_switch_saved');}}
    catch(error){if(!this.removed){this.values[key]=previous;this.error=error?.response?.errors?.[0]?.detail||t('api.post_images_storage_failed');}}
    finally{if(!this.removed){this.saving=false;m.redraw();}}
  }
  onremove(){this.removed=true;}
  block(image) { this.confirmAction(image, 'block'); }
  source(image) {
    return image.discussionId ? (image.discussionTitle||t('lib.post_images_unknown_title'))+' · '+t('lib.post_images_source',{discussion:image.discussionId,number:image.postNumber}) : t('lib.post_images_no_source');
  }
  preview(image) {
    const images=this.images.filter(row=>!row.deleted).map(row=>({...row,caption:this.source(row)}));
    app.modal.show(PhotoModal,{images,index:images.findIndex(row=>row.id===image.id)});
  }
  confirmDelete(image) { this.confirmAction(image, 'delete'); }
  confirmAction(image, action) {
    if(this.blocking||this.loading||this.saving||image.deleted||(action==='block'&&image.blocked))return;
    app.modal.show(PhotoActionModal,{image,action,source:this.source(image),run:async()=>{
      this.blocking=true;
      try {
        await app.request(action==='block' ? {method:'POST',url:this.api('/'+image.id+'/block')} : {method:'DELETE',url:this.api('/'+image.id+'/permanent'),body:{confirmId:image.id}});
        if(!this.removed){this.message=t(action==='block'?'lib.post_images_taken_down_notice':'lib.post_images_deleted_notice');await this.load();}
      } finally { this.blocking=false; }
    }});
  }
  field(key,label,type='text',attrs={}) {
    return m('.Form-group',[
      m('label',{for:'ccot-image-filter-'+key},t(label)),
      m('input.FormControl',{id:'ccot-image-filter-'+key,type,value:this.filters[key],disabled:this.loading||this.saving,oninput:e=>{this.filters[key]=e.target.value;},...attrs}),
    ]);
  }
  paginationView() {
    const data=this.pagination||{page:1,perPage:20,total:0,pages:1};
    return m('nav.CCOT-photo-pagination',{'aria-label':t('lib.post_images_pages')},[
      m('span',t('lib.post_images_page_summary',{total:data.total,page:data.page,pages:data.pages})),
      m(Button,{type:'button',className:'Button',disabled:this.loading||this.saving||data.page<=1,onclick:()=>this.load(data.page-1)},t('lib.post_images_previous')),
      m(Button,{type:'button',className:'Button',disabled:this.loading||this.saving||data.page>=data.pages,onclick:()=>this.load(data.page+1)},t('lib.post_images_next')),
      m('form.CCOT-photo-page-jump',{onsubmit:e=>{e.preventDefault();this.load(Number(this.pageInput));}},[
        m('input.FormControl',{type:'number',min:1,max:data.pages,'aria-label':t('lib.post_images_page_number'),value:this.pageInput,disabled:this.loading||this.saving,oninput:e=>{this.pageInput=e.target.value;}}),
        m(Button,{type:'submit',className:'Button',disabled:this.loading||this.saving},t('lib.post_images_go')),
      ]),
    ]);
  }
  view() {
    if(!this.values)return m('section',[m('p',{role:this.error?'alert':'status'},this.error||t('lib.post_images_loading')),this.error&&m(Button,{className:'Button',onclick:()=>this.load()},t('lib.post_images_refresh'))]);
    return m('section.CCOT-post-settings',[
      m('h2',t('lib.post_images_photos')),
      ...['enabled','simple'].map(key=>m('.Form-group',m(Switch,{state:this.values[key],disabled:this.saving||this.loading,onchange:value=>this.toggle(key,value)},t(labels[key])))),
      m('p.CCOT-help',t('lib.post_images_save_behavior')),
      ...['max_mb','max_mp','max_count','capacity_gb'].map(key=>m('.Form-group',[m('label',{for:'ccot-photo-'+key},t(labels[key])),m('input.FormControl',{id:'ccot-photo-'+key,type:'number',min:1,max:{max_mb:16,max_mp:40,max_count:9,capacity_gb:64}[key],value:this.values[key],disabled:this.saving||this.loading,oninput:e=>{this.values[key]=e.target.value;}})])),
      m('p.CCOT-help',t('lib.post_images_frequency')),m('p.CCOT-help',t('lib.post_images_preserve')),
      m(Button,{type:'button',className:'Button Button--primary',loading:this.saving,disabled:this.saving||this.loading,onclick:()=>this.save()},t('lib.post_images_save')),
      this.message&&m('p',{role:'status'},this.message),this.error&&m('p.Alert.Alert--error',{role:'alert'},this.error),
      m('p.CCOT-help',t('lib.post_images_storage',{path:this.storage})),m('p.CCOT-help',t('lib.post_images_cleanup')),
      this.cleanupError&&m('p.Alert.Alert--error',{role:'alert'},t('lib.post_images_cleanup_failed')),
      m('h3',t('lib.post_images_moderation')),
      m('form.CCOT-photo-filters',{onsubmit:e=>{e.preventDefault();this.applyFilters();}},[
        this.field('discussion','lib.post_images_filter_discussion','number',{min:1,max:4294967295}),
        this.field('user','lib.post_images_filter_user','text',{maxlength:60}),
        this.field('image','lib.post_images_filter_id','text',{pattern:'[a-f0-9]{32}',maxlength:32}),
        m('.Form-group',[m('label',{for:'ccot-image-filter-state'},t('lib.post_images_filter_state')),m('select.FormControl',{id:'ccot-image-filter-state',value:this.filters?.state||'all',disabled:this.loading||this.saving,onchange:e=>{this.filters.state=e.target.value;}},Object.keys(stateLabels).map(state=>m('option',{value:state},t(stateLabels[state]))))]),
        this.field('perPage','lib.post_images_per_page','number',{min:1,max:100,required:true}),
        m('.CCOT-links',[
          m(Button,{type:'submit',className:'Button Button--primary',disabled:this.loading||this.saving},t('lib.post_images_filter')),
          m(Button,{type:'button',className:'Button',disabled:this.loading||this.saving,onclick:()=>this.applyFilters(true)},t('lib.post_images_reset')),
          m(Button,{type:'button',className:'Button',disabled:this.loading||this.saving,onclick:()=>this.load()},t('lib.post_images_refresh')),
        ]),
      ]),
      this.paginationView(),
      this.loading&&m('p',{role:'status'},t('lib.post_images_list_loading')),
      !this.images.length&&m('p.CCOT-photo-empty',t('lib.post_images_empty')),
      m('ul.CCOT-photo-management',this.images.map(image=>m('li',{key:image.id},[
        image.deleted ? m('.CCOT-photo-deleted',t('lib.post_images_deleted')) : m('button.CCOT-photo-preview',{type:'button','aria-label':t('lib.post_images_preview_id',{id:image.id}),onclick:()=>this.preview(image)},m('img',{src:image.thumb,alt:t('lib.post_images_preview_id',{id:image.id}),loading:'lazy'})),
        m('.CCOT-photo-details',[
          m('p',image.postUrl?m('a',{href:image.postUrl,target:'_blank',rel:'noopener noreferrer'},this.source(image)):this.source(image)),
          image.sourceRemoved&&m('p',t('lib.post_images_source_removed')),
          image.postHidden&&m('p',t('lib.post_images_source_hidden')),
          m('p',image.uploader?m('a',{href:image.uploader.url,target:'_blank',rel:'noopener noreferrer'},t('lib.post_images_uploader_value',{name:image.uploader.name,id:image.uploader.id})):t('lib.post_images_unknown_user')),
          image.author&&image.author.id!==image.uploader?.id&&m('p',t('lib.post_images_author',{name:image.author.name,id:image.author.id})),
          m('p.CCOT-help',[new Date(image.createdAt*1000).toLocaleString(),' · ',image.width+'×'+image.height+' · '+Math.ceil(image.bytes/1024)+'KiB']),
          m('code',image.id),
        ]),
        m('.CCOT-photo-actions',[
          m('span',t(stateLabels[image.state])),
          !image.blocked&&!image.deleted&&m(Button,{type:'button',className:'Button',disabled:this.blocking||this.loading||this.saving,onclick:()=>this.block(image)},t('lib.post_images_take_down')),
          !image.deleted&&m(Button,{type:'button',className:'Button Button--danger',disabled:this.blocking||this.loading||this.saving,onclick:()=>this.confirmDelete(image)},t('lib.post_images_delete')),
        ]),
      ]))),
      this.paginationView(),
    ]);
  }
}
