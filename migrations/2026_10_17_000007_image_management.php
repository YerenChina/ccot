<?php

use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function ($schema) {
        if (! $schema->hasColumn('ccot_post_images', 'deleted_at')) {
            $schema->table('ccot_post_images', function (Blueprint $table) {
                $table->unsignedBigInteger('deleted_at')->nullable()->index();
                // Retain the last known source when a native post is removed or detached.
                $table->unsignedInteger('source_post_id')->nullable();
                $table->unsignedInteger('source_discussion_id')->nullable()->index();
                $table->unsignedInteger('source_number')->nullable();
                $table->string('source_title')->nullable();
                $table->index(['created_at', 'id'], 'ccot_post_images_created_id');
            });
        }
        $db = $schema->getConnection();
        $db->table('ccot_post_images as i')->join('posts as p', 'p.id', '=', 'i.post_id')
            ->leftJoin('discussions as d', 'd.id', '=', 'p.discussion_id')
            ->whereNull('i.source_post_id')->select('i.id', 'p.id as post', 'p.discussion_id', 'p.number', 'd.title')
            ->orderBy('i.id')->chunk(500, function ($rows) use ($db) {
                foreach ($rows as $row) $db->table('ccot_post_images')->where('id', $row->id)->update([
                    'source_post_id' => $row->post, 'source_discussion_id' => $row->discussion_id,
                    'source_number' => $row->number, 'source_title' => $row->title,
                ]);
            });
    },
    'down' => function ($schema) {
        resolve(\BushcraftCN\CCOT\AccountLifecycle::class)->assertAuthorized();
        if ($schema->hasColumn('ccot_post_images', 'deleted_at')) $schema->table('ccot_post_images', function (Blueprint $table) {
            $table->dropIndex('ccot_post_images_created_id');
            $table->dropColumn(['deleted_at', 'source_post_id', 'source_discussion_id', 'source_number', 'source_title']);
        });
    },
];
