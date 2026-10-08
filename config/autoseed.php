<?php

return [
    // Default number of rows per table.
    'default_rows' => 50,

    // Per-table override. Number = exact rows, "x10" = 10 * default_rows.
    'tables' => [
        // 'users'       => 200,
        // 'order_items' => 'x10',
    ],

    // Tables to skip (fnmatch patterns).
    'exclude' => [
        'migrations', 'failed_jobs', 'jobs', 'job_batches', 'cache', 'cache_locks',
        'sessions', 'password_reset_tokens', 'personal_access_tokens',
        'telescope_*', 'pulse_*',
    ],

    // Polymorphic relations: morph alias => table name. Relation::morphMap() is merged automatically.
    'morph_map' => [
        // 'post' => 'posts',
    ],

    'chunk_size'   => 500,   // rows per INSERT
    'pool_limit'   => 50000, // max parent ids kept in memory per FK
    'null_chance'  => 10,    // % chance to put NULL in nullable columns
    'self_ref_pct' => 70,    // % of rows that get a parent in self-referencing tables
    'locale'       => env('FAKER_LOCALE', 'en_US'),
];
