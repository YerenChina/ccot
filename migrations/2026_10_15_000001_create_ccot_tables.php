<?php

use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (Builder $schema) {
        $schema->create('ccot_phones', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->string('phone_hash', 64)->unique();
            $table->text('cipher');
            $table->unsignedBigInteger('verified_at');
            $table->string('region', 80)->default('未知');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
        $schema->create('ccot_codes', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('phone_hash', 64)->index();
            $table->string('code_hash', 64);
            $table->string('purpose', 16);
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('expires_at')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('state', 16);
        });
        $schema->create('ccot_limits', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->unsignedInteger('hits');
            $table->unsignedBigInteger('expires_at')->index();
        });
        $schema->create('ccot_blocks', function (Blueprint $table) {
            $table->string('phone_hash', 64)->primary();
            $table->unsignedBigInteger('expires_at')->index();
            $table->unsignedInteger('actor_id');
        });
        $schema->create('ccot_tickets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('type', 24);
            $table->text('message');
            $table->text('contact')->nullable();
            $table->text('reply')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->boolean('hold')->default(false);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    },
    'down' => function (Builder $schema) {
        resolve(\BushcraftCN\CCOT\AccountLifecycle::class)->assertAuthorized();
        foreach (['ccot_tickets', 'ccot_blocks', 'ccot_limits', 'ccot_codes', 'ccot_phones'] as $name) $schema->dropIfExists($name);
        $lifecycle = resolve(\BushcraftCN\CCOT\AccountLifecycle::class);
        \BushcraftCN\CCOT\OperationLock::run(resolve(\Flarum\Foundation\Paths::class), true, fn () => $lifecycle->finish());
    },
];
