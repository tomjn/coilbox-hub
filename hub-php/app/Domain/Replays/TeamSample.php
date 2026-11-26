<?php

namespace App\Domain\Replays;

/**
 * One statistics sample of a team. Every figure is a running total from the
 * start of the match to this frame.
 */
final readonly class TeamSample
{
    public function __construct(
        public int $frame,
        public float $metalUsed,
        public float $energyUsed,
        public float $metalProduced,
        public float $energyProduced,
        public float $metalExcess,
        public float $energyExcess,
        public float $metalReceived,
        public float $energyReceived,
        public float $metalSent,
        public float $energySent,
        public float $damageDealt,
        public float $damageReceived,
        public int $unitsProduced,
        public int $unitsDied,
        public int $unitsReceived,
        public int $unitsSent,
        public int $unitsCaptured,
        public int $unitsOutCaptured,
        public int $unitsKilled,
    ) {}

    public static function unpack(string $bytes, int $at): self
    {
        return new self(...array_values(unpack('lframe/g12f/l7i', $bytes, $at)));
    }
}
