<?php

use App\Auth\HubAccounts;
use App\Auth\HubSignIn;
use App\Auth\UserCopy;
use App\Domain\Assets\PictureCopy;
use App\Domain\Gallery\ItemCopy;
use App\Domain\Games\CatalogueCopy;
use App\Domain\Maps\MapCopy;
use App\Domain\Mirrors\CatalogueStore;
use App\Domain\Mirrors\HubKeys;
use App\Domain\Mirrors\MirrorCopy;
use App\Domain\Promotion\JobToken;
use App\Supabase\Local\GameStore;
use App\Supabase\Local\ItemStore;
use App\Supabase\Local\MapShadow;
use App\Supabase\Local\MapStore;
use App\Supabase\Local\MirrorStore;
use App\Supabase\Local\PictureStore;
use App\Supabase\Local\Shadow;
use App\Supabase\Local\SignInStore;
use App\Supabase\Local\TokenStore;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
    if (($blocked = SignInStore::blocksRollback()) !== null) {
        $this->error($blocked);

        return 1;
    }
    if (MirrorStore::fresh() !== MirrorStore::SUPABASE) {
        $this->error('The mirrors read games from MariaDB. Run mirrors:store supabase first.');

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
    if (($blocked = SignInStore::blocksRollback()) !== null) {
        $this->error($blocked);

        return 1;
    }
    if (PictureStore::fresh() === PictureStore::MARIADB) {
        $this->warn('Pictures uploaded or moderated since the switch are only in MariaDB and on this disk, not in Supabase.');
    }
    // Supabase's staged picture sweep reads its game rows again.
    Shadow::pushAll();
    PictureStore::set(PictureStore::SUPABASE);
    $this->line('The hub reads pictures from supabase.');

    return 0;
})->purpose('Show which database pictures are read from, or go back to Supabase');

Artisan::command('maps:copy {--dry-run : Show the row counts and write nothing}', function () {
    $copy = new MapCopy(
        Supabase::admin()->remote(),
        fn (string $line) => $this->line($line),
        fn (int $seconds) => sleep($seconds),
    );
    $this->line('The hub reads maps from '.MapStore::fresh().'.');

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
})->purpose('Copy maps, map authors and map packs from Supabase into MariaDB and switch the hub to them');

Artisan::command('maps:store {state? : supabase, to go back}', function () {
    $state = $this->argument('state');
    if ($state === null) {
        $this->line('The hub reads maps from '.MapStore::fresh().'.');

        return 0;
    }
    if ($state !== MapStore::SUPABASE) {
        $this->error('Only supabase can be set here. maps:copy switches to mariadb once the rows are copied.');

        return 1;
    }
    if (($blocked = SignInStore::blocksRollback()) !== null) {
        $this->error($blocked);

        return 1;
    }
    if (MirrorStore::fresh() !== MirrorStore::SUPABASE) {
        $this->error('The mirrors read maps from MariaDB. Run mirrors:store supabase first.');

        return 1;
    }
    if (ItemStore::fresh() !== ItemStore::SUPABASE) {
        $this->error('Reports read maps from MariaDB. Run items:store supabase first.');

        return 1;
    }
    if (MapStore::fresh() === MapStore::MARIADB) {
        $this->warn('Writes made in MariaDB since the switch are not in Supabase, apart from map rows and source conflicts.');
    }
    MapStore::set(MapStore::SUPABASE);
    $this->line('The hub reads maps from supabase.');

    return 0;
})->purpose('Show which database maps are read from, or go back to Supabase');

Artisan::command('mirrors:copy {--dry-run : Show the row counts and write nothing}', function () {
    $copy = new MirrorCopy(
        Supabase::admin()->remote(),
        fn (string $line) => $this->line($line),
        fn (int $seconds) => sleep($seconds),
    );
    $this->line('The hub reads the mirrors from '.MirrorStore::fresh().'.');
    $waiting = MirrorCopy::waitingFor();

    try {
        if ($this->option('dry-run')) {
            foreach ($copy->counts() as $table => $count) {
                $this->line(sprintf('%s: Supabase %d, MariaDB %d', $table, $count['supabase'], $count['mariadb']));
            }
            $this->line($waiting === null ? 'Games and maps are in MariaDB, so the copy can run.' : 'The copy would stop: '.$waiting);
            $this->line('Dry run: nothing was written.');

            return 0;
        }
        $copy->run();
    } catch (Throwable $e) {
        $this->error('The copy stopped: '.$e->getMessage());

        return 1;
    }

    return 0;
})->purpose('Copy the hub mirrors, their archives and the catalogue from Supabase into MariaDB and switch the hub to them');

Artisan::command('mirrors:store {state? : supabase, to go back}', function () {
    $state = $this->argument('state');
    if ($state === null) {
        $this->line('The hub reads the mirrors from '.MirrorStore::fresh().'.');

        return 0;
    }
    if ($state !== MirrorStore::SUPABASE) {
        $this->error('Only supabase can be set here. mirrors:copy switches to mariadb once the rows are copied.');

        return 1;
    }
    if (($blocked = SignInStore::blocksRollback()) !== null) {
        $this->error($blocked);

        return 1;
    }
    if (MirrorStore::fresh() === MirrorStore::MARIADB) {
        $this->warn('Reports, uploads, removals and mirror changes made in MariaDB since the switch are not in Supabase.');
    }
    // Supabase's archive functions read its game and map rows again.
    Shadow::pushAll();
    MapShadow::pushAll();
    MirrorStore::set(MirrorStore::SUPABASE);
    $this->line('The hub reads the mirrors from supabase.');

    return 0;
})->purpose('Show which database the hub mirrors are read from, or go back to Supabase');

Artisan::command('items:copy {--dry-run : Show the row counts and write nothing}', function () {
    $copy = new ItemCopy(
        Supabase::admin()->remote(),
        fn (string $line) => $this->line($line),
        fn (int $seconds) => sleep($seconds),
    );
    $this->line('The hub reads published items from '.ItemStore::fresh().'.');
    $waiting = ItemCopy::waitingFor();

    try {
        if ($this->option('dry-run')) {
            foreach ($copy->counts() as $table => $count) {
                $this->line(sprintf('%s: Supabase %d, MariaDB %d', $table, $count['supabase'], $count['mariadb']));
            }
            $this->line($waiting === null ? 'Maps are in MariaDB, so the copy can run.' : 'The copy would stop: '.$waiting);
            $this->line('Dry run: nothing was written.');

            return 0;
        }
        $copy->run();
    } catch (Throwable $e) {
        $this->error('The copy stopped: '.$e->getMessage());

        return 1;
    }

    return 0;
})->purpose('Copy published items, reports, profiles and moderator capabilities from Supabase into MariaDB and switch the hub to them');

Artisan::command('items:store {state? : supabase, to go back}', function () {
    $state = $this->argument('state');
    if ($state === null) {
        $this->line('The hub reads published items from '.ItemStore::fresh().'.');

        return 0;
    }
    if ($state !== ItemStore::SUPABASE) {
        $this->error('Only supabase can be set here. items:copy switches to mariadb once the rows are copied.');

        return 1;
    }
    if (($blocked = SignInStore::blocksRollback()) !== null) {
        $this->error($blocked);

        return 1;
    }
    if (ItemStore::fresh() === ItemStore::MARIADB) {
        $this->warn('Items, reports, profiles and capabilities changed in MariaDB since the switch are not in Supabase.');
    }
    // Reports in Supabase name maps by their Supabase rows, which were not kept in step meanwhile.
    MapShadow::pushAll();
    ItemStore::set(ItemStore::SUPABASE);
    $this->line('The hub reads published items from supabase.');

    return 0;
})->purpose('Show which database published items, reports, profiles and capabilities are read from, or go back to Supabase');

Artisan::command('users:copy {--dry-run : Show the counts and write nothing} {--switch : Then run the browser sign in on the hub}', function () {
    $switch = (bool) $this->option('switch');
    $copy = new UserCopy(fn (string $line) => $this->line($line));
    $this->line('The browser sign in runs on '.(SignInStore::fresh() === SignInStore::MARIADB ? 'the hub' : 'Supabase').'.');
    if ($switch && ($fixed = SignInStore::fixedMessage()) !== null) {
        $this->error($fixed);

        return 1;
    }
    if (($blocked = UserCopy::blocksCopy()) !== null) {
        $this->error($blocked);

        return 1;
    }
    $waiting = $switch ? UserCopy::waitingForSwitch() : [];

    try {
        if ($this->option('dry-run')) {
            $counts = $copy->counts();
        } else {
            if ($waiting !== []) {
                $this->error('Sign in cannot move yet:');
                array_map(fn (string $line) => $this->error('- '.$line), $waiting);

                return 1;
            }
            $counts = $switch ? $copy->switch() : $copy->run();
        }
    } catch (Throwable $e) {
        $this->error('The copy stopped: '.$e->getMessage());

        return 1;
    }
    $this->line(sprintf('Accounts: Supabase %d, MariaDB %d. Providers: Supabase %d, MariaDB %d.',
        $counts['supabase_users'], $counts['mariadb_users'], $counts['supabase_identities'], $counts['mariadb_identities']));
    if ($this->option('dry-run')) {
        $this->line(sprintf('To add: %d accounts. To remove: %d accounts Supabase no longer has.', $counts['to_add'], $counts['to_remove']));
        if ($switch) {
            $this->line($waiting === [] ? 'Sign in can move to the hub.' : 'Sign in cannot move yet:');
            array_map(fn (string $line) => $this->line('- '.$line), $waiting);
        }
        $this->line('Dry run: nothing was written.');
    } elseif ($switch) {
        $this->line('The browser sign in now runs on the hub. Coilbox still signs in with Supabase.');
    }

    return 0;
})->purpose('Copy Supabase Auth accounts and their sign in providers into MariaDB, keeping their ids. With --switch, then run the browser sign in on the hub');

Artisan::command('users:store {state? : supabase, to go back}', function () {
    $state = $this->argument('state');
    $where = fn () => SignInStore::fresh() === SignInStore::MARIADB ? 'the hub' : 'Supabase';
    if ($state === null) {
        $this->line('The browser sign in runs on '.$where().'.');

        return 0;
    }
    if ($state !== SignInStore::SUPABASE) {
        $this->error('Only supabase can be set here. users:copy --switch moves sign in to the hub once the accounts are copied.');

        return 1;
    }
    if (($fixed = SignInStore::fixedMessage()) !== null) {
        $this->error($fixed);

        return 1;
    }
    if (TokenStore::fresh() === TokenStore::MARIADB) {
        $this->error('Coilbox signs in on the hub. Run tokens:store supabase first.');

        return 1;
    }
    if (SignInStore::fresh() === SignInStore::MARIADB) {
        $made = UserCopy::madeOnHub();
        $this->warn(sprintf('%d accounts were made on the hub since the switch. Supabase does not have them, so signing in through Supabase gives each of those people a new, empty account.', $made));
        $this->warn('Providers linked or removed, and authenticator apps set up, on the hub are not in Supabase. Everybody signed in on the hub has to sign in again.');
    }
    SignInStore::set(SignInStore::SUPABASE);
    $this->line('The browser sign in runs on Supabase.');

    return 0;
})->purpose('Show where the browser sign in runs, or go back to Supabase');

Artisan::command('users:ban {user : The account id, or its email} {--until= : When the ban ends, such as 2027-01-31. Leave out for no end} {--lift : End the ban now} {--dry-run : Show the account and write nothing}', function () {
    if (SignInStore::fresh() !== SignInStore::MARIADB) {
        $this->error('Sign in runs on Supabase. Ban the account in the Supabase dashboard, and the copy carries the ban over.');

        return 1;
    }
    $found = HubAccounts::find((string) $this->argument('user'));
    if (count($found) !== 1) {
        $this->error($found === [] ? 'No account has that id or email.' : 'More than one account has that email: '.implode(', ', $found).'. Use the id.');

        return 1;
    }
    $userId = $found[0];
    try {
        $until = $this->option('lift') ? null : HubAccounts::banEnd($this->option('until'));
    } catch (InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $name = DB::table('profile')->where('user_id', $userId)->value('display_name') ?? 'no display name';
    $this->line(sprintf('Account %s (%s), banned until: %s.', $userId, $name, DB::table('auth_user')->where('id', $userId)->value('banned_until') ?? 'not banned'));
    if ($this->option('dry-run')) {
        $this->line($until === null ? 'Would lift the ban.' : 'Would ban until '.$until.' UTC.');
        $this->line('Dry run: nothing was written.');

        return 0;
    }
    HubAccounts::ban($userId, $until);
    $this->line($until === null
        ? 'The ban is lifted.'
        : 'Banned until '.$until.' UTC. Their browser session and hub tokens stop working on their next request. Copies of Coilbox that still sign in with Supabase keep working, so ban them in Supabase too.');

    return 0;
})->purpose('Ban an account from signing in on the hub, or lift a ban, once the hub runs sign in');

Artisan::command('tokens:store {state? : mariadb, to have the hub sign Coilbox in, or supabase, to go back} {--dry-run : Say what would change and write nothing}', function () {
    $state = $this->argument('state');
    $where = fn () => TokenStore::fresh() === TokenStore::MARIADB ? 'the hub' : 'Supabase';
    if ($state === null) {
        $this->line('Coilbox signs in with '.$where().'.');
        $this->line(sprintf('Hub sign ins in use: %d.', DB::table('auth_refresh_token')->whereNull('revoked_at')->count()));

        return 0;
    }
    if (! in_array($state, [TokenStore::MARIADB, TokenStore::SUPABASE], true)) {
        $this->error('Give mariadb or supabase.');

        return 1;
    }
    if (($fixed = TokenStore::fixedMessage()) !== null) {
        $this->error($fixed);

        return 1;
    }
    if ($state === TokenStore::MARIADB) {
        if (SignInStore::fresh() !== SignInStore::MARIADB) {
            $this->error('The browser sign in runs on Supabase. Run users:copy --switch first.');

            return 1;
        }
        $missing = array_values(array_filter(UserCopy::SUPABASE_PROVIDERS, fn (string $provider) => ! HubSignIn::configured($provider)));
        if ($missing !== []) {
            $this->error('Set the client id and secret for: '.implode(', ', $missing).'.');

            return 1;
        }
    }
    $this->line('Coilbox signs in with '.$where().'.');
    if ($this->option('dry-run')) {
        $this->line('Would have Coilbox sign in with '.($state === TokenStore::MARIADB ? 'the hub' : 'Supabase').'.');
        $this->line('Dry run: nothing was written.');

        return 0;
    }
    TokenStore::set($state);
    $this->line($state === TokenStore::MARIADB
        ? 'Coilbox now signs in with the hub. Supabase tokens it holds keep working, and each is swapped for the hub\'s at its next refresh.'
        : 'Coilbox now signs in with Supabase. Tokens the hub already issued keep working.');

    return 0;
})->purpose('Show where Coilbox signs in and gets its tokens, or switch it between the hub and Supabase');

Artisan::command('users:duplicates', function () {
    // Made when somebody signed in to Coilbox through Supabase after the
    // browser sign in moved, with a provider a hub account already held. The
    // hub added the Supabase account without the provider.
    $rows = DB::table('auth_user')->where('copy_hash', '')
        ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('auth_identity')->whereColumn('auth_identity.user_id', 'auth_user.id'))
        ->orderBy('created_at')->get(['id', 'email', 'created_at']);
    $this->line(sprintf('Accounts with no provider, added from Coilbox after the browser sign in moved: %d.', $rows->count()));
    foreach ($rows as $row) {
        $same = $row->email === null ? [] : DB::table('auth_user')->where('id', '!=', $row->id)
            ->whereRaw('lower(email) = ?', [mb_strtolower((string) $row->email)])->pluck('id')->all();
        $this->line(sprintf('%s  %s  made %s  same email: %s', $row->id, $row->email ?? 'no email', $row->created_at, $same === [] ? 'none' : implode(', ', $same)));
    }

    return 0;
})->purpose('List the second accounts made by signing in to Coilbox through Supabase after the browser sign in moved. Writes nothing');

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
