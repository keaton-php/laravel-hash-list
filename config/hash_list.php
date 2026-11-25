<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Hash Algorithm
    |--------------------------------------------------------------------------
    |
    | The digest used to hash emails: "sha256" or "md5". Use "md5" only when
    | you must exchange hash files with a system that publishes md5 lists.
    | Every list in the table must use the same algorithm; changing this
    | after entries exist makes those entries unreachable.
    |
    */
    'algorithm' => env('HASH_LIST_ALGORITHM', 'sha256'),

    /*
    |--------------------------------------------------------------------------
    | Table And Connection
    |--------------------------------------------------------------------------
    |
    | The table that holds the hashes and, optionally, the database
    | connection it lives on. A null connection uses the application default.
    | The published migration names its own table and connection; if you
    | change these, edit the migration to match before you run it.
    |
    */
    'table' => env('HASH_LIST_TABLE', 'hash_list_entries'),

    'connection' => env('HASH_LIST_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Default List
    |--------------------------------------------------------------------------
    |
    | The list used when you do not call on('name'). A list name is 1 to 64
    | characters.
    |
    */
    'default_list' => env('HASH_LIST_DEFAULT_LIST', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Strip Plus Tags
    |--------------------------------------------------------------------------
    |
    | When true, "user+news@example.com" is treated as "user@example.com"
    | before hashing, both on the way in and on lookup. Only turn this on if
    | the hash files you exchange were built the same way.
    |
    */
    'strip_plus_tags' => env('HASH_LIST_STRIP_PLUS_TAGS', false),

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Rows per INSERT during an import and per query during an export. Each
    | inserted row binds three values, so lower this if you run SQLite older
    | than 3.32 (a limit of 999 bound values).
    |
    */
    'chunk_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | Send-Time Guard
    |--------------------------------------------------------------------------
    |
    | When true, listed recipients are removed from every outgoing
    | message, and the send is canceled if nobody is left. "guard_list"
    | names the list to check; null uses the default list. "guard" is read
    | on every send, and "guard_list" on every send while the guard is on.
    |
    */
    'guard' => env('HASH_LIST_GUARD', false),

    'guard_list' => null,

];
