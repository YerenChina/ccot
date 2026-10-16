<?php
namespace BushcraftCN\CCOT\PostImages;
use BushcraftCN\CCOT\{I18n, Problem};
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Psr\Http\Message\UploadedFileInterface;
final class Processor
{
    public function process(UploadedFileInterface $file, array $limits): array
    {
        if($file->getError()!==UPLOAD_ERR_OK)throw new Problem(I18n::t('api.post_images_upload_failed'),422);
        $size=$file->getSize();if(!$size||$size>$limits['max_mb']*1048576)throw new Problem(I18n::t('api.post_images_size_limit'),422);
        $stream=$file->getStream();$stream->rewind();$bytes=$stream->read($limits['max_mb']*1048576+1);
        if(strlen($bytes)!==$size)throw new Problem(I18n::t('api.post_images_size_limit'),422);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($mime,['image/jpeg','image/png','image/webp'],true)||($info['mime']??'')!==$mime)throw new Problem(I18n::t('api.post_images_format'),422);
        // Reject animated PNG/WebP before decoding; do not silently keep only the first frame.
        if(($mime==='image/png'&&$this->animatedPng($bytes))||($mime==='image/webp'&&$this->animatedWebp($bytes)))throw new Problem(I18n::t('api.post_images_animated'),422);
        $pixels=(float)$info[0]*$info[1];
        if($info[0]<1||$info[1]<1||$info[0]>20000||$info[1]>20000||$pixels>$limits['max_mp']*1000000)throw new Problem(I18n::t('api.post_images_pixel_limit'),422);
        $memory=ini_get('memory_limit');$limit=$memory==='-1'?PHP_INT_MAX:$this->bytes($memory);
        if(memory_get_usage(true)+$pixels*12+33554432>$limit)throw new Problem(I18n::t('api.post_images_memory_limit'),422);
        try {
            // Mature codec handles all eight EXIF orientations before resize. GD output has no EXIF/GPS.
            $image=(new ImageManager(Driver::class,autoOrientation:true,decodeAnimation:false,strip:true))->read($bytes);
            // GD's mature decoder extracts EXIF only from JPEG/TIFF. PNG/WebP may
            // carry the same TIFF metadata in a bounded container chunk: let PHP's
            // EXIF reader parse it, then let Intervention perform the transform once.
            $orientation=$mime==='image/jpeg'?null:$this->orientation($bytes,$mime);
            if($orientation!==null)$image->setExif(new \Intervention\Image\Collection(['IFD0'=>['Orientation'=>$orientation]]))->orient();
            $image->scaleDown(width:2560,height:2560);
            $width=$image->width();$height=$image->height();$full=(string)$image->toWebp(85);
            $image->scaleDown(width:480,height:480);$thumb=(string)$image->toWebp(80);
        } catch(\Throwable) {throw new Problem(I18n::t('api.post_images_decode_failed'),422);}
        return ['image'=>$full,'thumb'=>$thumb,'width'=>$width,'height'=>$height,'source_hash'=>hash('sha256',$bytes)];
    }
    private function bytes(string $size): int {return (int)$size*match(strtolower(substr($size,-1))){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};}
    private function orientation(string $bytes,string $mime): ?int
    {
        $png=$mime==='image/png';$orientation=null;
        for($p=$png?8:12;$p+($png?12:8)<=strlen($bytes);){
            $length=unpack($png?'N':'V',substr($bytes,$p+($png?0:4),4))[1];$type=substr($bytes,$p+($png?4:0),4);$overhead=$png?12:8;
            if($length>strlen($bytes)-$p-$overhead)throw new \RuntimeException('Invalid image container');
            if($type===($png?'eXIf':'EXIF')){
                if($length>262144||$orientation!==null)throw new \RuntimeException('Unsupported EXIF block');
                $raw=substr($bytes,$p+8,$length);if(str_starts_with($raw,"Exif\0\0"))$raw=substr($raw,6);
                $stream=fopen('php://temp','r+');try{fwrite($stream,$raw);rewind($stream);$exif=@exif_read_data($stream,null,true);}finally{fclose($stream);}
                if(!is_array($exif))throw new \RuntimeException('Unreadable EXIF');
                $orientation=$exif['IFD0']['Orientation']??1;
                if(!is_int($orientation)||$orientation<0||$orientation>8)throw new \RuntimeException('Invalid EXIF orientation');
            }
            $p+=$overhead+$length+($png?0:$length%2);
        }
        return $orientation;
    }
    private function animatedPng(string $bytes): bool
    {
        for($p=8;$p+12<=strlen($bytes);){$length=unpack('N',substr($bytes,$p,4))[1];$type=substr($bytes,$p+4,4);if($type==='acTL')return true;if($length>strlen($bytes)-$p-12)throw new Problem(I18n::t('api.post_images_decode_failed'),422);$p+=12+$length;}
        return false;
    }
    private function animatedWebp(string $bytes): bool
    {
        for($p=12;$p+8<=strlen($bytes);){$type=substr($bytes,$p,4);$length=unpack('V',substr($bytes,$p+4,4))[1];if(in_array($type,['ANIM','ANMF'],true)||($type==='VP8X'&&$length>0&&(ord($bytes[$p+8])&2)))return true;if($length>strlen($bytes)-$p-8)throw new Problem(I18n::t('api.post_images_decode_failed'),422);$p+=8+$length+($length%2);}
        return false;
    }
}
