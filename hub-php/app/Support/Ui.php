<?php

namespace App\Support;

/** Class strings shared across views, from components/Button.tsx. */
final class Ui
{
    private const BASE = 'rounded-md transition-colors disabled:opacity-60 group-aria-busy:cursor-progress group-aria-busy:opacity-60';

    private const SIZES = [
        'xs' => 'px-2 py-1 text-xs',
        'sm' => 'px-3 py-1 text-xs',
        'md' => 'px-3 py-1.5 text-sm',
        'base' => 'px-4 py-2 text-sm',
        'lg' => 'px-5 py-2.5 text-sm',
    ];

    private const VARIANTS = [
        'primary' => 'bg-neutral-100 font-medium text-neutral-900 hover:bg-white active:bg-neutral-300',
        'ghost' => 'border border-neutral-800 text-neutral-300 hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white',
        'quiet' => 'border border-neutral-800 text-neutral-400 hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200',
        'destructive' => 'border border-red-900 text-red-300 hover:border-red-700 active:border-red-600 hover:text-red-200 active:text-red-200',
    ];

    /** A text field, as the account forms draw one. */
    public const FIELD = 'w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-400 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400';

    /** `buttonClass` in the Next app. */
    public static function button(string $variant = 'ghost', string $size = 'base', string $class = ''): string
    {
        return trim(self::BASE.' '.self::SIZES[$size].' '.self::VARIANTS[$variant].' '.$class);
    }
}
