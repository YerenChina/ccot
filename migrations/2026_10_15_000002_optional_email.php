<?php

use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (Builder $schema) {
        $schema->table('users', fn (Blueprint $table) => $table->string('email', 150)->nullable()->change());
    },
    'down' => function (Builder $schema) {
        resolve(\BushcraftCN\CCOT\AccountLifecycle::class)->assertAuthorized();
        // Retain nullability when needed by surviving native accounts. Core still
        // requires a real email for NEW native registrations after CCOT is removed.
        if (! $schema->getConnection()->table('users')->whereNull('email')->exists()) $schema->table('users', fn (Blueprint $table) => $table->string('email', 150)->nullable(false)->change());
    },
];
