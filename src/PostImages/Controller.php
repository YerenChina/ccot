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
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor=RequestUtil::getActor($request);$path=$request->getUri()->getPath();$params=$request->getAttribute('routeParameters')??[];$id=$params['id']??'';$method=$request->getMethod();$body=$request->getParsedBody()??[];if(!is_array($body))throw new Problem(I18n::t('api.post_images_invalid_request'),422);
            if(str_ends_with($path,'/settings')){if(!$actor->isAdmin())throw new Problem(I18n::t('api.administrator_permission_required'),403);return PrivateResponse::json(match($method){'POST'=>$this->settings->save($body),'PATCH'=>$this->settings->toggle($body),default=>$this->settings->read()});}
            if(str_ends_with($path,'/manage')){
                if(!$actor->isAdmin())throw new Problem(I18n::t('api.administrator_permission_required'),403);
                $base=resolve(\Flarum\Http\UrlGenerator::class)->to('forum')->base();
                return PrivateResponse::json((new Management($this->store->db,$base))->read($request->getQueryParams()));
            }
            if(str_ends_with($path,'/preview')){
                if(!$actor->isAdmin())throw new Problem(I18n::t('api.administrator_permission_required'),403);
                $variant=$request->getQueryParams()['variant']??'image';
                if(!is_string($variant))throw new Problem(I18n::t('api.post_images_invalid_request'),422);
                $file=$this->store->previewFile($id,$variant);
                $stream=fopen($file,'rb');
                if(!$stream)throw new Problem(I18n::t('api.post_images_storage_failed'),503);
                return new Response($stream,200,['Content-Type'=>'image/webp','Content-Length'=>(string)filesize($file),'Cache-Control'=>'private, no-store, max-age=0','Vary'=>'Cookie, Authorization','X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"default-src 'none'; sandbox",'Referrer-Policy'=>'no-referrer']);
            }
            if($method==='DELETE'&&str_ends_with($path,'/permanent')){
                if(!$actor->isAdmin())throw new Problem(I18n::t('api.administrator_permission_required'),403);
                if(($body['confirmId']??null)!==$id)throw new Problem(I18n::t('api.post_images_delete_confirmation'),422);
                $this->store->permanentlyDelete($id);return PrivateResponse::json(['deleted'=>true]);
            }
            if($method==='POST'&&$id===''){$files=$request->getUploadedFiles();$file=$files['image']??null;if(count($files)!==1||!$file instanceof UploadedFileInterface)throw new Problem(I18n::t('api.post_images_upload_failed'),422);return PrivateResponse::json($this->store->upload($actor,$body,$file),201);}
            if(str_ends_with($path,'/block')){if(!$actor->isAdmin())throw new Problem(I18n::t('api.administrator_permission_required'),403);$this->store->block($id);return PrivateResponse::json(['blocked'=>true]);}
            if($method==='DELETE'){$this->store->assertVerified($actor);$row=$this->store->db->table('ccot_post_images')->where('id',$id)->first();if(!$row||(int)$row->user_id!==$actor->id)throw new Problem(I18n::t('api.post_images_not_found'),404);if($row->post_id)throw new Problem(I18n::t('api.post_images_still_bound'),409);$this->store->db->table('ccot_post_images')->where('id',$id)->update(['unused_at'=>time()]);return PrivateResponse::json(['removed'=>true]);}
            throw new Problem(I18n::t('api.endpoint_not_found'),404);
        }catch(Problem $e){return PrivateResponse::json(['errors'=>[['status'=>(string)$e->status,'detail'=>$e->getMessage()]]],$e->status);}
        catch(\Throwable){return PrivateResponse::json(['errors'=>[['status'=>'503','detail'=>I18n::t('api.post_images_storage_failed')]]],503);}
    }
}
