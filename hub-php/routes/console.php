<?php

use App\Domain\Assets\PictureCopy;
use App\Domain\Games\CatalogueCopy;
use App\Domain\Mirrors\CatalogueStore;
use App\Domain\Mirrors\HubKeys;
use App\Domain\Promotion\JobToken;
use App\Supabase\Local\GameStore;
use App\Supabase\Local\PictureStore;
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

Artisan::command('games:copy {--dry-run : Show the row counts and write nothing}', function () {
    $copy = new CatalogueCopy(
        Supabase::admin()->remote(),
        fn (string $line) => $this->line($line),
        fn (int $seconds) => sleep($seconds),
    );
    $this->line('The hub reads games from '.GameStore::fresh().'.');

    try {
        if ($this->option('dry-run')) {
            foreach ($copy->counts() as $table => $count) {
                $this->line(sprintf('%s: Supabase %d, MariaDB %d', $table, $count['supabase'], $count['mariadb']));
            }
            $this->line('Dry run: nothing was written.');

            return 0;
        }
        $copy->run();
    } catch (Throwable $e) {
        $this->error('The copy stopped: '.$e->getMessage());

        return 1;
    }

    return 0;
})->purpose('Copy the game catalogue from Supabase into MariaDB and switch the hub to it');

Artisan::command('games:store {state? : supabase, to go back}', function () {
    $state = $this->argument('state');
    if ($state === null) {
        $this->line('The hub reads games from '.GameStore::fresh().'.');

        return 0;
    }
    if ($state !== GameStore::SUPABASE) {
        $this->error('Only supabase can be set here. games:copy switches to mariadb once the rows are copied.');

        return 1;
    }
    if (GameStore::fresh() === GameStore::MARIADB) {
        $this->warn('Writes made in MariaDB since the switch are not in Supabase, apart from game rows themselves.');
    }
    GameStore::set(GameStore::SUPABASE);
    $this->line('The hub reads games from supabase.');

    return 0;
})->purpose('Show which database the game catalogue is read from, or go back to Supabase');

Artisan::command('pictures:copy {--dry-run : Show the row and object counts, and the free space, and write nothing}', function () {
    $copy = new PictureCopy(
        Supabase::admin()->remote(),
        fn (string $line) => $this->line($line),
        fn (int $seconds) => sleep($seconds),
        fn (string $folder) => disk_free_space($folder),
    );
    $this->line('The hub reads pictures from '.PictureStore::fresh().'.');

    try {
        if ($this->option('dry-run')) {
            foreach ($copy->counts() as $table => $count) {
                $this->line(sprintf('%s: Supabase %d, MariaDB %d', $table, $count['supabase'], $count['mariadb']));
            }
            $objects = $copy->objectCounts();
            $this->line(sprintf('staged-pictures: Supabase %d objects, %d bytes. Disk %d objects, %d bytes.',
                $objects['supabase_objects'], $objects['supabase_bytes'], $objects['local_objects'], $objects['local_bytes']));
            $this->line(sprintf('To copy: %d objects, %d bytes. Free: %s bytes.', $objects['to_copy'], $objects['to_copy_bytes'],
                $objects['free_bytes'] === false ? 'unknown' : (string) (int) $objects['free_bytes']));
            $this->line('Dry run: nothing was written.');

            return 0;
        }
        $copy->run();
    } catch (Throwable $e) {
        $this->error('The copy stopped: '.$e->getMessage());
        $this->line('Running it again keeps the objects already copied and copies the rest.');

        return 1;
    }

    return 0;
})->purpose('Copy picture records into MariaDB and staged pictures onto this disk, and switch the hub to them');

Artisan::command('pictures:store {state? : supabase, to go back}', function () {
    $state = $this->argument('state');
    if ($state === null) {
        $this->line('The hub reads pictures from '.PictureStore::fresh().'.');

        return 0;
    }
    if ($state !== PictureStore::SUPABASE) {
        $this->error('Only supabase can be set here. pictures:copy switches to mariadb once the rows and objects are copied.');

        return 1;
    }
    if (PictureStore::fresh() === PictureStore::MARIADB) {
        $this->warn('Pictures uploaded or moderated since the switch are only in MariaDB and on this disk, not in Supabase.');
    }
    PictureStore::set(PictureStore::SUPABASE);
    $this->line('The hub reads pictures from supabase.');

    return 0;
})->purpose('Show which database pictures are read from, or go back to Supabase');

Artisan::command('promotion:token', function () {
    $made = JobToken::make();
    $this->line('Token, for the PROMOTION_TOKEN secret in tomjn/coilbox-assets. It is not stored anywhere else:');
    $this->line($made['token']);
    $this->line('');
    $this->line('Hash, for PROMOTION_TOKEN_HASH (or PROMOTION_NEXT_TOKEN_HASH while rotating) in the hub\'s .env:');
    $this->line($made['hash']);
    $this->line('');
    $this->line('Run php artisan config:cache after changing .env.');

    return 0;
})->purpose('Make a token for the daily promotion job, and the hash the hub keeps');
