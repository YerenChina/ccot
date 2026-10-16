<?php
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function ($schema) {
        if ($schema->hasTable('ccot_post_images')) return; // Files survive uninstall; the active extension recreates its index.
        $schema->create('ccot_post_images', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->unsignedInteger('user_id')->nullable()->index();
            $t->unsignedInteger('post_id')->nullable()->index();
            $t->string('request_key', 64); $t->string('source_hash', 64);
            $t->unsignedInteger('width'); $t->unsignedInteger('height');
            $t->unsignedBigInteger('bytes'); $t->unsignedBigInteger('thumb_bytes');
            $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('unused_at')->nullable()->index();
            $t->unsignedBigInteger('blocked_at')->nullable();
            $t->unique(['user_id', 'request_key']);
            $t->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $t->foreign('post_id')->references('id')->on('posts')->onDelete('set null');
        });
    },
    // Public post files and native post links survive; CCOT-specific metadata does not.
    'down' => function ($schema) {
        resolve(\BushcraftCN\CCOT\AccountLifecycle::class)->assertAuthorized();
        $schema->dropIfExists('ccot_post_images');
    },
];
