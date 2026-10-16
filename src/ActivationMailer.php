<?php

namespace BushcraftCN\CCOT;

use Flarum\User\AccountActivationMailer;
use Flarum\User\Event\Registered;

final class ActivationMailer extends AccountActivationMailer
{
    public function handle(Registered $event): void
    {
        if ($event->user->email) parent::handle($event);
    }
}
