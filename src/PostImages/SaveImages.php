<?php
namespace BushcraftCN\CCOT\PostImages;
use BushcraftCN\CCOT\{I18n, Problem};
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
final class SaveImages
{
    public function __construct(private Store $store,private Settings $settings) {}
    public function handle(Saving $event): void
    {
        $post=$event->post;if(!$post instanceof CommentPost||!$post->isDirty('content'))return;
        $ids=Markup::ids($post->parsed_content??'');$before=$post->exists?$this->store->db->table('ccot_post_images')->where('post_id',$post->id)->pluck('id')->all():[];
        if(!$ids&&!$before)return;
        $this->store->assertVerified($event->actor);
        if(count($ids)!==count(array_unique($ids))||(count($ids)>$this->settings->read()['max_count']&&array_diff($ids,$before)))throw new Problem(I18n::t('api.post_images_count_limit'),422);
        if($this->store->db->transactionLevel()<1)throw new Problem(I18n::t('api.post_images_save_failed'),503);
        foreach($ids as$id){$row=$this->store->db->table('ccot_post_images')->where('id',$id)->lockForUpdate()->first();$existing=$row&&$post->exists&&(int)$row->post_id===$post->id;if(!$row||(!$existing&&((int)$row->user_id!==$event->actor->id||$row->post_id!==null))||($row->blocked_at!==null&&!$existing))throw new Problem(I18n::t('api.post_images_permission'),403);if(!$existing&&!$this->settings->read()['enabled'])throw new Problem(I18n::t('api.post_images_disabled'),403);}
        $post->afterSave(function($post)use($ids){$db=$this->store->db;$db->table('ccot_post_images')->where('post_id',$post->id)->whereNotIn('id',$ids)->update(['post_id'=>null,'unused_at'=>time()]);if($ids)$db->table('ccot_post_images')->whereIn('id',$ids)->update(['post_id'=>$post->id,'unused_at'=>null,'source_post_id'=>$post->id,'source_discussion_id'=>$post->discussion_id,'source_number'=>$post->number,'source_title'=>$db->table('discussions')->where('id',$post->discussion_id)->value('title')]);});
    }
}
