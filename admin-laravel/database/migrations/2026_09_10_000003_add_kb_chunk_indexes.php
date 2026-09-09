<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;   // SQLite scans in memory; it needs no index
        }

        DB::statement("ALTER TABLE kb_chunks ADD COLUMN content_tsv tsvector
                       GENERATED ALWAYS AS (to_tsvector('english', content)) STORED");
        DB::statement('CREATE INDEX kb_chunks_tsv_idx ON kb_chunks USING GIN (content_tsv)');
        DB::statement('CREATE INDEX kb_chunks_vec_idx ON kb_chunks
                       USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS kb_chunks_vec_idx');
        DB::statement('DROP INDEX IF EXISTS kb_chunks_tsv_idx');
        DB::statement('ALTER TABLE kb_chunks DROP COLUMN IF EXISTS content_tsv');
    }
};
