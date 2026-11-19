<?php

namespace App\Supabase\Local;

use App\Supabase\SupabaseException;

/**
 * A refusal from the local store, carrying the PostgREST or Postgres code
 * Supabase would have sent, so callers that branch on `errorCode` keep working.
 */
class LocalException extends SupabaseException {}
