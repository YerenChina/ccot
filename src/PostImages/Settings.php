<?php
namespace BushcraftCN\CCOT\PostImages;
use BushcraftCN\CCOT\{I18n, Problem};
use Flarum\Settings\SettingsRepositoryInterface;
final class Settings
{
    public const PREFIX = 'bushcraftcn-ccot.post_images.';
    public const DEFAULTS = ['enabled'=>false,'simple'=>true,'max_mb'=>8,'max_mp'=>24,'max_count'=>6,'capacity_gb'=>2];
    public function __construct(private SettingsRepositoryInterface $settings) {}
    public function read(): array
    {
        $out=[];
        foreach (self::DEFAULTS as $key=>$default) {
            $value=$this->settings->get(self::PREFIX.$key);
            $out[$key]=is_bool($default) ? ($value===null ? $default : $value==='1') : (int) ($value??$default);
        }
        foreach (['max_mb'=>[1,16],'max_mp'=>[1,40],'max_count'=>[1,9],'capacity_gb'=>[1,64]] as $key=>[$min,$max]) if($out[$key]<$min||$out[$key]>$max)$out[$key]=self::DEFAULTS[$key];
        return $out;
    }
    public function save(array $body): array
    {
        $next=[];
        foreach (['enabled','simple'] as $key) {if(!is_bool($body[$key]??null))throw new Problem(I18n::t('api.post_images_invalid_settings'),422);$next[$key]=$body[$key];}
        foreach (['max_mb'=>[1,16],'max_mp'=>[1,40],'max_count'=>[1,9],'capacity_gb'=>[1,64]] as $key=>[$min,$max]) {
            $v=$body[$key]??null;if(!(is_int($v)||is_string($v)&&preg_match('/^[0-9]+$/D',$v))||(int)$v<$min||(int)$v>$max)throw new Problem(I18n::t('api.post_images_invalid_settings'),422);$next[$key]=(int)$v;
        }
        foreach($next as $key=>$value)$this->settings->set(self::PREFIX.$key,is_bool($value)?($value?'1':'0'):(string)$value);
        return $this->read();
    }
}
