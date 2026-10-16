<?php

namespace BushcraftCN\CCOT;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\Driver\EmailNotificationDriver;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

readonly class EmailDriver extends EmailNotificationDriver
{
    protected function mailNotifications(MailableInterface&BlueprintInterface $blueprint, array $recipients): void
    {
        parent::mailNotifications($blueprint, array_filter($recipients, fn (User $user) => $user->email && $user->is_email_confirmed));
    }
}
