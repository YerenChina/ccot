<?php

namespace BushcraftCN\CCOT;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\AccountActivationMailer;

final class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(RegistrationContext::class);
        $this->container->bind(AccountActivationMailer::class, ActivationMailer::class);
    }
}
