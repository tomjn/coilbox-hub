<?php

use App\Domain\Mirrors\CatalogueStore;
use App\Domain\Mirrors\HubKeys;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mirrors:catalogue', function () {
    $keys = HubKeys::fromConfig();
    if ($keys === null) {
        $this->error('No valid HUB_MIRROR_SECRET_KEY is set, so the catalogue cannot be signed.');

        return 1;
    }

    try {
        $admin = Supabase::admin();
        $store = new CatalogueStore($admin, $keys);
        if (! $store->rebuild()) {
            $this->error('The catalogue was not rebuilt. See the log for the database error.');

            return 1;
        }
        $state = $admin->from('catalogue_state')->select('seq')->maybeSingle();
    } catch (SupabaseException $e) {
        $this->error('The catalogue was not rebuilt: '.$e->getMessage());

        return 1;
    }

    $this->line('catalogue seq '.($state['seq'] ?? 'unknown'));

    return 0;
})->purpose('Rebuild and sign the mirror catalogue now');

Artisan::command('mirrors:status', function () {
    $yesNo = fn ($value) => $value ? 'yes' : 'no';

    try {
        $admin = Supabase::admin();
        $mirrors = $admin->from('mirror')
            ->select('id,enabled,healthy,last_report_at,last_catalogue_seq,last_catalogue_generated_at')
            ->order('id', true)
            ->get();
        $state = $admin->from('catalogue_state')->select('seq,next_key_public,next_key_since_seq')->maybeSingle();
        $stale = (new CatalogueStore($admin, HubKeys::fromConfig()))->isStale();
    } catch (SupabaseException $e) {
        $this->error('Could not read the mirror tables: '.$e->getMessage());

        return 1;
    }

    foreach ($mirrors as $m) {
        $generated = $m['last_catalogue_generated_at'] ?? null;
        $this->line(sprintf(
            '%s enabled %s healthy %s last report %s catalogue seq %s generated %s',
            $m['id'],
            $yesNo($m['enabled'] ?? false),
            $yesNo($m['healthy'] ?? false),
            $m['last_report_at'] ?? 'never',
            $m['last_catalogue_seq'] ?? 'none',
            $generated === null ? 'never' : gmdate('Y-m-d\TH:i:s\Z', (int) $generated),
        ));
    }

    $this->line('stored catalogue seq '.($state['seq'] ?? 'none').($stale ? ', stale' : ', current'));

    $next = HubKeys::fromConfig()?->nextKey();
    if ($next === null) {
        $this->line('no next key set');

        return 0;
    }
    $this->line('next key '.$next['public_key']);

    $since = $state['next_key_since_seq'] ?? null;
    if ($since === null || ($state['next_key_public'] ?? null) !== $next['public_key']) {
        $this->line('waiting for: a catalogue carrying the next key. Run mirrors:catalogue.');

        return 0;
    }

    $this->line('next key since seq '.$since);
    $waiting = [];
    foreach ($mirrors as $m) {
        if (! ($m['enabled'] ?? false)) {
            continue;
        }
        $seq = $m['last_catalogue_seq'] ?? null;
        if ($seq === null || (int) $seq < (int) $since) {
            $waiting[] = $m['id'];
        }
    }
    $this->line($waiting === [] ? 'safe to switch keys' : 'waiting for: '.implode(', ', $waiting));

    return 0;
})->purpose('Show each mirror and whether the next hub key is safe to switch to');
