<?php

namespace App\Console\Commands;

use App\Domain\Replays\ReplayException;
use App\Domain\Replays\ReplayReader;
use Illuminate\Console\Command;

class ReplaysRead extends Command
{
    protected $signature = 'replays:read {path : A .sdfz or .sdf replay file}';

    protected $description = 'Read a replay and print what it holds, the time the read took and its peak memory';

    public function handle(ReplayReader $reader): int
    {
        $path = (string) $this->argument('path');
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = hrtime(true);
        try {
            $replay = $reader->read($path);
        } catch (ReplayException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $ms = (hrtime(true) - $start) / 1e6;
        $peak = memory_get_peak_usage() - $before;

        $h = $replay->header;
        $s = $replay->script;
        $stream = $replay->stream;
        $origins = [];
        foreach ($stream->orders as $order) {
            $origins[$order->origin] = ($origins[$order->origin] ?? 0) + 1;
        }
        $kinds = array_count_values(array_map(fn ($m) => $m->kind, $stream->luaMessages));
        ksort($origins);
        ksort($kinds);

        $this->line("File: {$path} (".number_format((int) @filesize($path)).' bytes)');
        $this->line("Game: {$s->gameType} on {$s->map}, engine {$h->engineVersion}");
        $this->line("Length: {$h->gameTime} s, ".count($s->players).' players, '.count($s->ais).' AIs, '.count($s->teams).' teams');
        $this->line('Packets: '.number_format($stream->packets).", last frame {$stream->lastFrame}");
        $this->line('Orders: '.json_encode($origins).', chat '.count($stream->chat).', events '.count($stream->events));
        $this->line('Lua messages: '.number_format($stream->luaMessageCount).', decoded '.json_encode($kinds));
        $end = $replay->endOfGame;
        $this->line($end === null ? 'No end of game block' : 'Winners: '.json_encode($end->winningAllyTeams).', samples per team '.json_encode(array_map('count', $end->teams)));
        $this->line(sprintf('Read in %.1f ms, peak memory %.1f MB above the %.1f MB in use before', $ms, $peak / 1048576, $before / 1048576));

        return self::SUCCESS;
    }
}
