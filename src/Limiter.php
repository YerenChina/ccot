<?php

namespace BushcraftCN\CCOT;

use Illuminate\Database\ConnectionInterface;

final class Limiter
{
    public function __construct(private ConnectionInterface $db, private Crypto $crypto) {}

    /** Each bucket is charged atomically; failed later checks do not erase earlier charges. */
    public function take(string $name, string $identity, int $seconds, int $maximum): int
    {
        $now = time();
        $key = $this->crypto->digest('limit', $name.':'.$identity.':'.intdiv($now, $seconds));
        return $this->db->transaction(function () use ($key, $now, $seconds, $maximum) {
            $this->db->table('ccot_limits')->insertOrIgnore(['id' => $key, 'hits' => 0, 'expires_at' => $now + $seconds]);
            $row = $this->db->table('ccot_limits')->where('id', $key)->lockForUpdate()->first();
            if ($row->hits >= $maximum) throw new Problem(I18n::t('api.too_many_requests_please_retry_later'), 429);
            $this->db->table('ccot_limits')->where('id', $key)->increment('hits');
            return $row->hits + 1;
        });
    }

    public function cooldown(string $name, string $identity, int $seconds): void
    {
        $key = $this->crypto->digest('limit', $name.':'.$identity);
        $this->db->transaction(function () use ($key, $seconds) {
            $this->db->table('ccot_limits')->insertOrIgnore(['id' => $key, 'hits' => 0, 'expires_at' => 0]);
            $row = $this->db->table('ccot_limits')->where('id', $key)->lockForUpdate()->first();
            if ($row->hits && $row->expires_at > time()) {
                $wait = $row->expires_at - time();
                throw new Problem(I18n::t('api.send_wait', ['seconds'=>$wait]), 429, null, $wait);
            }
            $this->db->table('ccot_limits')->where('id', $key)->update(['hits' => 1, 'expires_at' => time() + $seconds]);
        });
    }

    public function cooldownRemaining(string $name, string $identity): int
    {
        $key = $this->crypto->digest('limit', $name.':'.$identity);
        $row = $this->db->table('ccot_limits')->where('id', $key)->first();
        return $row && $row->hits ? max(0, $row->expires_at - time()) : 0;
    }

    public function count(string $name, string $identity, int $seconds): int
    {
        $key = $this->crypto->digest('limit', $name.':'.$identity.':'.intdiv(time(), $seconds));
        return (int) ($this->db->table('ccot_limits')->where('id', $key)->value('hits') ?? 0);
    }

    /** One explicit test grant, with a lifetime budget that does not reset at midnight. */
    public function scoped(string $grant, int $maximum, int $expires): void
    {
        $this->checkScoped($grant, $maximum, $expires);
        $key = $this->crypto->digest('limit', 'sms.live-test:'.$grant);
        $this->db->transaction(function () use ($key, $maximum, $expires) {
            $this->db->table('ccot_limits')->insertOrIgnore(['id' => $key, 'hits' => 0, 'expires_at' => $expires]);
            $row = $this->db->table('ccot_limits')->where('id', $key)->lockForUpdate()->first();
            if ($row->expires_at <= time() || $row->hits >= $maximum) throw new Problem(I18n::t('api.the_sms_test_budget_is_exhausted_or_expired'), 429);
            $this->db->table('ccot_limits')->where('id', $key)->increment('hits');
        });
    }

    public function checkScoped(string $grant, int $maximum, int $expires): void
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $grant) || $expires <= time()) throw new Problem(I18n::t('api.this_sms_test_has_expired_restart_it_in_administration'), 403);
        if ($this->scopedCount($grant) >= $maximum) throw new Problem(I18n::t('api.this_sms_test_budget_is_exhausted_restart_it_in_administration'), 429);
    }

    public function scopedCount(string $grant): int
    {
        return (int) $this->db->table('ccot_limits')->where('id', $this->crypto->digest('limit', 'sms.live-test:'.$grant))->value('hits');
    }
}
