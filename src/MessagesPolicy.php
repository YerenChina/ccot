<?php

namespace BushcraftCN\CCOT;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

final class MessagesPolicy extends AbstractPolicy
{
    public function message(User $actor, User $recipient): ?string
    {
        if ($recipient->getPreference('ccotRejectMessages')
            || in_array((int) $actor->id, $recipient->getPreference('ccotBlockedUsers', []), true)) return $this->forceDeny();
        return null;
    }
}
