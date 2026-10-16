<?php

namespace BushcraftCN\CCOT;

use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

final class PhoneGroups
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(User $user, array $groups): array
    {
        if ($user->isGuest() || $user->isAdmin()) return $groups;
        if ($user->suspended_until && $user->suspended_until->isFuture()) return [Group::GUEST_ID];
        $phone = $this->db->table('ccot_phones')->where('user_id', $user->id)->first();
        if ($phone && ! $this->db->table('ccot_blocks')->where('phone_hash', $phone->phone_hash)->where('expires_at', '>', time())->exists()) {
            return array_unique(array_merge($groups, [Group::MEMBER_ID], $user->groups->pluck('id')->all()));
        }
        // A pre-existing verified email must not expose member/custom-role abilities
        // before this SAME account finishes phone verification. Stored roles are intact.
        return [Group::GUEST_ID];
    }
}
