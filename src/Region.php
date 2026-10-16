<?php

namespace BushcraftCN\CCOT;

use Flarum\Foundation\Config;

final class Region
{
    public function __construct(private Config $config) {}

    public function locate(string $ip): string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return I18n::t('lib.unknown');
        try {
            $search = new \Ip2Region('file', null, $this->config['ccot.region_v6_file']);
            $parts = explode('|', $search->search($ip));
            $country = $parts[0] ?? '';
            $province = $parts[1] ?? '';
            if ($country === '中国') return $province && $province !== '0' ? $province : '中国';
            return $country && $country !== '0' ? $country : I18n::t('lib.unknown');
        } catch (\Throwable) {
            return I18n::t('lib.unknown');
        }
    }
}
