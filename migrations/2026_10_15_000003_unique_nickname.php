<?php

use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (Builder $schema) {
        $duplicates = $schema->getConnection()->table('users')->whereNotNull('nickname')->select('nickname')->groupBy('nickname')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) throw new RuntimeException('CCOT: resolve duplicate nicknames before installing the uniqueness constraint');
        $schema->table('users', fn (Blueprint $table) => $table->unique('nickname', 'ccot_users_nickname_unique'));
    },
    'down' => function (Builder $schema) {
        resolve(\BushcraftCN\CCOT\AccountLifecycle::class)->assertAuthorized();
        $schema->table('users', fn (Blueprint $table) => $table->dropUnique('ccot_users_nickname_unique'));
    },
];
