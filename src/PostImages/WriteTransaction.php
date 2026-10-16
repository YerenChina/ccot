<?php
namespace BushcraftCN\CCOT\PostImages;
use BushcraftCN\CCOT\{PrivateResponse,Problem};
use Psr\Http\Server\{MiddlewareInterface,RequestHandlerInterface};
use Psr\Http\Message\{ServerRequestInterface,ResponseInterface};
final class WriteTransaction implements MiddlewareInterface
{
    private static int $depth=0;
    public function __construct(private Store $store) {}
    public function process(ServerRequestInterface $request,RequestHandlerInterface $handler): ResponseInterface
    {
        $path=preg_replace('~^/api(?=/|$)~','',$request->getUri()->getPath());
        if(self::$depth||!in_array($request->getMethod(),['POST','PATCH','DELETE'],true)||!preg_match('~^/(posts|discussions)(?:/[1-9][0-9]*)?$~D',$path))return $handler->handle($request);
        try{return $this->store->lock(function()use($request,$handler){self::$depth++;$db=$this->store->db;$db->beginTransaction();try{$response=$handler->handle($request);if($response->getStatusCode()<400)$db->commit();else$db->rollBack();return $response;}catch(\Throwable $e){$db->rollBack();throw $e;}finally{self::$depth--;}});}
        catch(Problem $e){return PrivateResponse::json(['errors'=>[['status'=>(string)$e->status,'detail'=>$e->getMessage()]]],$e->status);}
    }
}
