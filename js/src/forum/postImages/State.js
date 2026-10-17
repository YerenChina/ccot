import app from 'flarum/forum/app';
import t from '../../common/t';
export function photoToken(id) { const base = app.forum.attribute('baseUrl') + '/assets/ccot-post-images/' + id; return `[url=${base}-image.webp][img]${base}-thumb.webp[/img][/url]`; }
function requestKey() { const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join(''); }
export default class PhotoState {
  constructor(owner, images, context, simple, composer) {
    this.owner = owner; this.items = images.map(image => ({ ...image, status: 'done', progress: 100, existing: true, key: image.id }));
    this.originalIds = this.items.map(i => i.id).join(','); this.context = context; this.simple = simple; this.composer = composer; this.error = ''; this.queue = Promise.resolve();
  }
  changed() { return this.items.some(i => i.status !== 'done') || this.items.map(i => i.id).join(',') !== this.originalIds; }
  pending() { return this.items.some(i => i.status !== 'done'); }
  select(files) {
    const options = app.forum.attribute('ccotPostImages'); this.error = '';
    if (this.items.length + files.length > options.max_count) { this.error = t('lib.post_images_max', { count: options.max_count }); return; }
    for (const file of files) {
      const item = { file, key: requestKey(), status: 'waiting', progress: 0 };
      this.items.push(item); this.queue = this.queue.then(() => this.upload(item));
    }
  }
  async upload(item) {
    if (!this.items.includes(item)) return;
    if (app.session.user?.id() !== this.owner) { item.status = 'failed'; this.error = t('api.post_images_permission'); m.redraw(); return; }
    item.status = 'uploading'; item.progress = 0; this.error = ''; m.redraw();
    const form = new FormData(); form.append('image', item.file); form.append('request_key', item.key); Object.entries(this.context).forEach(([k,v]) => form.append(k,v));
    try {
      const result = await app.request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/ccot/post-images', body: form,
        config: xhr => { xhr.upload.onprogress = event => { if (event.lengthComputable) { item.progress = Math.round(event.loaded / event.total * 100); m.redraw(); } }; } });
      if (app.session.user?.id() !== this.owner || !this.items.includes(item)) return;
      Object.assign(item, result, { status: 'done', progress: 100, file: null });
      if (!this.simple) { this.composer.editor.insertAtCursor('\n\n' + photoToken(item.id)); item.existing = true; }
    } catch (error) { item.status = 'failed'; this.error = error?.response?.errors?.[0]?.detail || t('lib.post_images_failed'); }
    m.redraw();
  }
  retry(item) { if (item.status !== 'failed') return; item.status = 'waiting'; this.queue = this.queue.then(() => this.upload(item)); }
  remove(item) {
    if (item.status === 'uploading') return;
    this.items = this.items.filter(i => i !== item); this.error = '';
    // Defer bound image removal to the native post save. Pending removal only marks it unused.
    if (item.id && !item.postId) app.request({ method: 'DELETE', url: app.forum.attribute('apiUrl') + '/ccot/post-images/' + item.id }).catch(() => {});
  }
  compose(text) { const ids = this.items.filter(i => i.status === 'done').map(i => i.id); return this.simple && ids.length ? text + '\n\n' + ids.map(photoToken).join('\n') : text; }
}
