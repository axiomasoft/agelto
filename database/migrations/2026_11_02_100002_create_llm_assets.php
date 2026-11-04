<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Canonical provider assets: file identity scoped by provider/account/API surface,
 * and context-cache identity scoped additionally by model and ordered content hashes.
 * Creation token and lease are stored together; active assets require a provider address
 * and expiration timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE llm_file_assets (
                id uuid PRIMARY KEY, provider_id bigint NOT NULL REFERENCES llm_providers(id),
                account_id text NOT NULL, api_surface text NOT NULL,
                content_hash bytea NOT NULL CHECK(octet_length(content_hash)=32), mime text NOT NULL,
                provider_uri text NULL, state text NOT NULL CHECK(state IN ('creating','PROCESSING','ACTIVE','FAILED','expired')),
                expires_at timestamptz NULL, creation_token uuid NULL REFERENCES llm_provider_calls(physical_call_id),
                creation_lease_until timestamptz NULL,
                CHECK((creation_token IS NULL)=(creation_lease_until IS NULL)),
                CHECK(state NOT IN ('PROCESSING','ACTIVE') OR (provider_uri IS NOT NULL AND expires_at IS NOT NULL)),
                UNIQUE(provider_id,account_id,api_surface,content_hash,mime)
            );
            CREATE TABLE llm_context_caches (
                id uuid PRIMARY KEY, provider_id bigint NOT NULL REFERENCES llm_providers(id),
                account_id text NOT NULL, api_surface text NOT NULL, model text NOT NULL,
                system_hash bytea NOT NULL CHECK(octet_length(system_hash)=32),
                schema_tool_policy_hash bytea NOT NULL CHECK(octet_length(schema_tool_policy_hash)=32),
                representation_hash bytea NOT NULL CHECK(octet_length(representation_hash)=32),
                content_ordering_hash bytea NOT NULL CHECK(octet_length(content_ordering_hash)=32),
                provider_name text NULL, expires_at timestamptz NULL,
                creation_state text NOT NULL CHECK(creation_state IN ('creating','active','expired')),
                creation_token uuid NULL REFERENCES llm_provider_calls(physical_call_id), creation_lease_until timestamptz NULL,
                cost numeric(24,8) NOT NULL CHECK(cost>=0),
                CHECK((creation_token IS NULL)=(creation_lease_until IS NULL)),
                CHECK(creation_state<>'active' OR (provider_name IS NOT NULL AND expires_at IS NOT NULL)),
                UNIQUE(provider_id,account_id,api_surface,model,system_hash,schema_tool_policy_hash,representation_hash,content_ordering_hash)
            );
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE llm_context_caches; DROP TABLE llm_file_assets;');
    }
};
