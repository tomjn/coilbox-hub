@props(['item', 'maps' => [], 'units' => [], 'roles' => []])
{{-- components/SetupPackContents.tsx: what a pack installs, shown rather
     than listed (#176). The layouts it carries are drawn by
     resources/js/gallery, which knows which of them parse. --}}
@php
    use App\Domain\Gallery\Previews;
    $payload = $item['container']['payload'] ?? null;
    $heading = 'text-xs uppercase tracking-wide text-neutral-400';
@endphp
@if (is_array($payload))
    @php
        $games = Previews::setupPackGameNames($payload);
        $engine = Previews::setupPackEngine($payload);
        $hasBlueprints = is_array($payload['blueprints'] ?? null) && $payload['blueprints'] !== [];
    @endphp
    @if ($games !== [] || $engine !== null || $maps !== [] || $hasBlueprints)
        <div class="flex flex-col gap-8">
            @if ($games !== [])
                <section class="flex flex-col gap-3">
                    <h2 class="{{ $heading }}">{{ count($games) === 1 ? 'Game' : 'Games' }}</h2>
                    <ul class="flex flex-col gap-1 text-sm text-neutral-100">
                        @foreach ($games as $game)<li>{{ $game }}</li>@endforeach
                    </ul>
                </section>
            @endif
            @if ($engine !== null)
                <section class="flex flex-col gap-3">
                    <h2 class="{{ $heading }}">Engine</h2>
                    <p class="text-sm text-neutral-100">{{ $engine }}</p>
                </section>
            @endif
            @if ($maps !== [])
                <section class="flex flex-col gap-3">
                    <h2 class="{{ $heading }}">{{ count($maps) === 1 ? 'Map' : 'Maps' }}</h2>
                    <ul class="grid gap-6 sm:grid-cols-2 md:grid-cols-3">
                        @foreach ($maps as $map)
                            <li class="flex"><x-gallery.map-minimap class="h-full w-full" :name="$map['name']" :picture="$map['picture']" :catalog="$map['catalog']" /></li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @if ($hasBlueprints)
                <div data-pack-blueprints="{{ json_encode(['payload' => $item['payload'], 'gameKey' => $item['game_key'], 'units' => (object) $units, 'roles' => (object) $roles], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"></div>
            @endif
        </div>
    @endif
@endif
