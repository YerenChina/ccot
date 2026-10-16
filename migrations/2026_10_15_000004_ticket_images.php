<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('ccot_ticket_images', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->unsignedInteger('ticket_id')->index();
            $table->string('mime', 32);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->foreign('ticket_id')->references('id')->on('ccot_tickets')->onDelete('cascade');
        });
    },
    'down' => function (Builder $schema) {
        resolve(\BushcraftCN\CCOT\AccountLifecycle::class)->purgeImages();
        $schema->dropIfExists('ccot_ticket_images');
    },
];
