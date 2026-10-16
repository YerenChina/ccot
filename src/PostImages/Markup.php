<?php
namespace BushcraftCN\CCOT\PostImages;
use Flarum\Post\CommentPost;
final class Markup
{
    public static function token(string $id): string {$store=resolve(Store::class);return '[url='.$store->url($id,'image').'][img]'.$store->url($id,'thumb').'[/img][/url]';}
    public static function ids(string $xml): array
    {
        if(!str_contains($xml,'/ccot/post-images/')&&!str_contains($xml,'/assets/ccot-post-images/'))return [];
        $doc=new \DOMDocument();$old=libxml_use_internal_errors(true);
        try {if(!$doc->loadXML($xml,LIBXML_NONET))return []; $out=[];$url=resolve(\Flarum\Http\UrlGenerator::class);$base=preg_quote($url->to('forum')->base().'/assets/ccot-post-images/','~');$oldBase=preg_quote($url->to('api')->base().'/ccot/post-images/','~');foreach($doc->getElementsByTagName('IMG')as$node)if(preg_match('~^(?:'.$base.'([a-f0-9]{32})-(?:image|thumb)\.webp|'.$oldBase.'([a-f0-9]{32})/(?:image|thumb))$~D',$node->getAttribute('src'),$m))$out[]=($m[1]?:$m[2]);return array_values(array_unique($out));}
        finally {libxml_clear_errors();libxml_use_internal_errors($old);}
    }
    public static function editor(CommentPost $post): array
    {
        $raw=$post->content??'';$ids=self::ids($post->parsed_content??'');$text=$raw;
        if($ids){$suffix="\n\n".implode("\n",array_map(self::token(...),$ids));if(!str_ends_with($raw,$suffix))return ['simple'=>false,'text'=>$raw];$text=substr($raw,0,-strlen($suffix));}
        // Decide using the native formatter's structure. No global string replacement of user text.
        // Unknown extension tags (for example legacy upload tags) must stay in source mode,
        // even if that extension is currently unavailable to the formatter.
        if(preg_match('/\[\/?[a-zA-Z][a-zA-Z0-9_-]*(?:[=\s\]])/',$text))return ['simple'=>false,'text'=>$raw];
        $xml=CommentPost::getFormatter()->parse($text,$post,$post->user);$doc=new \DOMDocument();$old=libxml_use_internal_errors(true);$simple=false;
        try {if($doc->loadXML($xml,LIBXML_NONET)){$simple=true;foreach($doc->getElementsByTagName('*')as$node)if(!in_array($node->nodeName,['r','t','p','br','s','e','URL','EMAIL'],true)){$simple=false;break;}}}
        finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        return ['simple'=>$simple,'text'=>$simple?$text:$raw];
    }
}
