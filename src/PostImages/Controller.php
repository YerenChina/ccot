<?php
namespace BushcraftCN\CCOT\PostImages;
use BushcraftCN\CCOT\{I18n,PrivateResponse,Problem};
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response;
use Psr\Http\Message\{ServerRequestInterface,ResponseInterface,UploadedFileInterface};
use Psr\Http\Server\RequestHandlerInterface;
final class Controller implements RequestHandlerInterface
{
    public function __construct(private Store $store,private Settings $settings) {}
    private function postUrl(int $id): ?string { $post=\Flarum\Post\Post::find($id);return $post?resolve(\Flarum\Http\UrlGenerator::class)->to('forum')->base().'/d/'.$post->discussion_id.'/'.$post->number:null; }
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor=RequestUtil::getActor($request);$path=$request->getUri()->getPath();$params=$request->getAttribute('routeParameters')??[];$id=$params['id']??'';$method=$request->getMethod();$body=$request->getParsedBody()??[];if(!is_array($body))throw new Problem(I18n::t('api.post_images_invalid_request'),422);
            if(str_ends_with($path,'/settings')){if(!$actor->isAdmin())throw new Problem(I18n::t('api.administrator_permission_required'),403);return PrivateResponse::json($method==='POST'?$this->settings->save($body):$this->settings->read());}
            if(str_ends_with($path,'/manage')){
                if(!$actor->isAdmin())throw new Problem(I18n::t('api.administrator_permission_required'),403);
                $rows=$this->store->db->table('ccot_post_images')->orderByDesc('created_at')->limit(50)->get()->map(fn($r)=>$this->store->data($r)+['postUrl'=>$r->post_id ? $this->postUrl((int)$r->post_id) : null,'userId'=>$r->user_id,'createdAt'=>$r->created_at,'bytes'=>(int)$r->bytes+(int)$r->thumb_bytes]);return PrivateResponse::json(['images'=>$rows->all(),'candidates'=>$this->store->candidates(),'storage'=>'public/assets/ccot-post-images']);
            }
            if($method==='POST'&&$id===''){$files=$request->getUploadedFiles();$file=$files['image']??null;if(count($files)!==1||!$file instanceof UploadedFileInterface)throw new Problem(I18n::t('api.post_images_upload_failed'),422);return PrivateResponse::json($this->store->upload($actor,$body,$file),201);}
            if(str_ends_with($path,'/block')){if(!$actor->isAdmin())throw new Problem(I18n::t('api.administrator_permission_required'),403);$this->store->block($id);return PrivateResponse::json(['blocked'=>true]);}
            if($method==='DELETE'){$this->store->assertVerified($actor);$row=$this->store->db->table('ccot_post_images')->where('id',$id)->first();if(!$row||(int)$row->user_id!==$actor->id)throw new Problem(I18n::t('api.post_images_not_found'),404);if($row->post_id)throw new Problem(I18n::t('api.post_images_still_bound'),409);$this->store->db->table('ccot_post_images')->where('id',$id)->update(['unused_at'=>time()]);return PrivateResponse::json(['removed'=>true]);}
            throw new Problem(I18n::t('api.endpoint_not_found'),404);
        }catch(Problem $e){return PrivateResponse::json(['errors'=>[['status'=>(string)$e->status,'detail'=>$e->getMessage()]]],$e->status);}
        catch(\Throwable){return PrivateResponse::json(['errors'=>[['status'=>'503','detail'=>I18n::t('api.post_images_storage_failed')]]],503);}
    }
}
