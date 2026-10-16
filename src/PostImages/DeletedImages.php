<?php
namespace BushcraftCN\CCOT\PostImages;
use Flarum\Post\Event\Deleted;
final class DeletedImages
{
    public function __construct(private Store $store) {}
    public function handle(Deleted|\Flarum\Discussion\Event\Deleting $event): void
    {
        if($event instanceof \Flarum\Discussion\Event\Deleting){
            // Native discussion deletion cascades in SQL and does not emit a post
            // event for every reply. Preserve a fresh retention date before that cascade.
            $posts=$this->store->db->table('posts')->where('discussion_id',$event->discussion->id)->select('id');
            $this->store->db->table('ccot_post_images')->whereIn('post_id',$posts)->update(['unused_at'=>time()]);
            return;
        }
        // Native hard deletion has already detached the FK. Start the retention period
        // at deletion, without deleting files or touching images still bound elsewhere.
        $ids=Markup::ids($event->post->parsed_content??'');
        if($ids)$this->store->db->table('ccot_post_images')->whereIn('id',$ids)->whereNull('post_id')->whereNull('unused_at')->update(['unused_at'=>time()]);
    }
}
