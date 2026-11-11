@props(['payload', 'units' => [], 'names' => []])
{{-- components/ProjectChanges.tsx: what a project does, unit by unit, capped at
     the row limit with the remainder said as a count. $units maps a lower case
     unit name to a resolved picture, $names a lower case def to label and href. --}}
@php
    use App\Domain\Gallery\ModProject;

    $changes = ModProject::changelog($payload);
    $details = $changes === [] ? [] : ModProject::details($payload);
    $shown = array_slice($changes, 0, ModProject::CHANGELOG_ROW_LIMIT);
    $hidden = count($changes) - count($shown);
    $pictured = collect($shown)->contains(fn ($change) => isset($units[$change['unit']]));
    $name = fn (string $def) => $names[mb_strtolower($def)] ?? null;
@endphp
@if ($changes !== [])
@php
    // A unit by the name the catalog gives it, linked to its page, with the
    // game's own internal name beside it. A unit the catalog does not know
    // keeps the raw key alone.
    $unitName = function (string $def) use ($name): string {
        $known = $name($def);
        if ($known === null) {
            return '<span class="break-all">'.e($def).'</span>';
        }

        return '<a href="'.e($known['href']).'" class="hover:text-white active:text-white">'.e($known['label']).'</a> <span class="break-all text-neutral-400">('.e($def).')</span>';
    };
@endphp
<section class="flex flex-col gap-2">
  <h2 class="text-lg font-semibold tracking-tight">What it changes</h2>
  <p class="text-sm text-neutral-400">{{ ModProject::describeProject(ModProject::countsFromPayload($payload)) }}</p>
  <ul class="flex flex-col divide-y divide-neutral-900">
    @foreach ($shown as $change)
      @php
        $detail = $details[$change['unit']] ?? null;
        $picture = $units[$change['unit']] ?? null;
      @endphp
      <li class="flex gap-4 py-4">
        <div class="{{ $pictured ? 'size-12 shrink-0 overflow-hidden rounded border border-neutral-800 bg-black' : 'hidden' }}">
          @if ($picture)
            <img src="{{ $picture['url'] }}" alt="" width="{{ $picture['width'] }}" height="{{ $picture['height'] }}" decoding="async" loading="lazy" class="size-full object-cover {{ $change['disabled'] ? 'opacity-40 grayscale' : '' }}">
          @endif
        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-2">
          <h3 class="text-sm font-medium text-neutral-100">{!! $unitName($change['unit']) !!}</h3>
          @if ($change['disabled'])
            <p class="text-sm text-neutral-400">Switched off. Nothing can build it.</p>
          @elseif ($change['added'])
            <p class="text-sm text-neutral-400">{!! $change['source'] !== null ? 'A new unit, copied from '.$unitName($change['source']) : 'A new unit' !!}</p>
          @endif

          @if ($detail && $detail['fields'] !== [])
            <dl class="grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-4 gap-y-1 font-mono text-sm">
              @foreach ($detail['fields'] as [$path, $value])
                <div class="contents">
                  <dt class="break-all text-neutral-400">{{ $path }}</dt>
                  <dd class="whitespace-pre-wrap break-all text-neutral-100">{{ $value }}</dd>
                </div>
              @endforeach
            </dl>
          @endif

          @if ($detail && $detail['text'] !== [])
            <ul class="flex flex-col gap-1 text-sm text-neutral-400">
              @foreach ($detail['text'] as [$language, $field, $words])
                <li>{{ $field === 'name' ? 'Renamed' : 'Described as' }} <q class="text-neutral-100">{{ $words }}</q> <span class="text-neutral-400">({{ $language }})</span></li>
              @endforeach
            </ul>
          @endif

          @if ($detail && $detail['menu'] !== [])
            <ol class="flex flex-col gap-1 text-sm text-neutral-400">
              @foreach ($detail['menu'] as $op)
                <li>
                  @if ($op['op'] === 'add')
                    Adds {!! $unitName($op['unit']) !!} to its build menu
                  @elseif ($op['op'] === 'remove')
                    Takes {!! $unitName($op['unit']) !!} off its build menu
                  @elseif ($op['before'] === null)
                    Moves {!! $unitName($op['unit']) !!} to the end of its build menu
                  @else
                    Moves {!! $unitName($op['unit']) !!} before {!! $unitName($op['before']) !!}
                  @endif
                </li>
              @endforeach
            </ol>
          @endif
        </div>
      </li>
    @endforeach
  </ul>
  @if ($hidden > 0)
    <p class="text-sm text-neutral-400">and {{ $hidden }} more unit{{ $hidden === 1 ? '' : 's' }}</p>
  @endif
</section>
@endif
