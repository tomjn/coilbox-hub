@props(['drawing', 'strength' => 1])
{!! \App\Domain\Gallery\Art\CoilArt::svg($drawing, $strength, $attributes->get('class')) !!}