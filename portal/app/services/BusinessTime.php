<?php
declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class BusinessTime
{
    /** @var null|callable(DateTimeImmutable):bool */
    private $isHoliday = null;

    public function __construct(
        private readonly DateTimeZone $tz,
        private readonly string $startTime, // '08:00:00'
        private readonly string $endTime,   // '17:00:00'
        private readonly int $workdaysMask  // Mon..Sun bitmask (Mon=2 ... Fri=32)
    ) {}

    public static function fromRow(array $row): self
    {
        $tz = new DateTimeZone((string)($row['tz'] ?? 'America/Bogota'));
        return new self(
            tz: $tz,
            startTime: (string)($row['start_time'] ?? '08:00:00'),
            endTime: (string)($row['end_time'] ?? '17:00:00'),
            workdaysMask: (int)($row['workdays_mask'] ?? 62)
        );
    }

    /**
     * ✅ NUEVO (compatible): inyecta un validador de festivos
     * callable(DateTimeImmutable $dt): bool
     */
    public function withHolidayChecker(callable $fn): self
    {
        $clone = clone $this;
        $clone->isHoliday = $fn;
        return $clone;
    }

    /** Regla estándar: “el reloj ANS empieza en el primer minuto hábil posterior a la llegada”. */
    public function normalizeStart(DateTimeImmutable $receivedAt): DateTimeImmutable
    {
        $dt = $receivedAt->setTimezone($this->tz);

        if (!$this->isWorkday($dt)) {
            return $this->nextWorkdayStart($dt);
        }

        $start = $this->atTime($dt, $this->startTime);
        $end   = $this->atTime($dt, $this->endTime);

        if ($dt < $start) return $start;
        if ($dt >= $end)  return $this->nextWorkdayStart($dt);
        return $dt;
    }

    /** Minutos hábiles acumulados entre start (ya normalizado) y end */
    public function diffBusinessMinutes(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $s = $start->setTimezone($this->tz);
        $e = $end->setTimezone($this->tz);
        if ($e <= $s) return 0;

        $minutes = 0;
        $cursor = $s;

        while ($cursor < $e) {
            if (!$this->isWorkday($cursor)) {
                $cursor = $this->nextWorkdayStart($cursor);
                continue;
            }

            $dayStart = $this->atTime($cursor, $this->startTime);
            $dayEnd   = $this->atTime($cursor, $this->endTime);

            if ($cursor < $dayStart) $cursor = $dayStart;

            if ($cursor >= $dayEnd) {
                $cursor = $this->nextWorkdayStart($cursor);
                continue;
            }

            $segmentEnd = ($e < $dayEnd) ? $e : $dayEnd;
            $delta = (int)floor(($segmentEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            if ($delta > 0) $minutes += $delta;

            $cursor = $segmentEnd;

            if ($cursor >= $dayEnd) {
                $cursor = $this->nextWorkdayStart($cursor);
            }
        }

        return $minutes;
    }

    public function addBusinessMinutes(DateTimeImmutable $start, int $minutesToAdd): DateTimeImmutable
    {
        $dt = $start->setTimezone($this->tz);
        if ($minutesToAdd <= 0) return $dt;

        $cursor = $this->normalizeStart($dt);
        $remaining = $minutesToAdd;

        while ($remaining > 0) {
            if (!$this->isWorkday($cursor)) {
                $cursor = $this->nextWorkdayStart($cursor);
                continue;
            }

            $dayStart = $this->atTime($cursor, $this->startTime);
            $dayEnd   = $this->atTime($cursor, $this->endTime);

            if ($cursor < $dayStart) $cursor = $dayStart;
            if ($cursor >= $dayEnd) { $cursor = $this->nextWorkdayStart($cursor); continue; }

            $available = (int)floor(($dayEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            if ($available <= 0) { $cursor = $this->nextWorkdayStart($cursor); continue; }

            $consume = min($available, $remaining);
            $cursor = $cursor->add(new DateInterval('PT' . $consume . 'M'));
            $remaining -= $consume;

            if ($cursor >= $dayEnd && $remaining > 0) {
                $cursor = $this->nextWorkdayStart($cursor);
            }
        }

        return $cursor;
    }

    private function isWorkday(DateTimeImmutable $dt): bool
    {
        $d = $dt->setTimezone($this->tz);

        // 1) Festivo => NO hábil
        if ($this->isHoliday !== null) {
            try {
                $fn = $this->isHoliday;
                if ($fn($d)) return false;
            } catch (\Throwable) {
                // Si falla el checker, NO rompemos el SLA; seguimos con regla normal.
            }
        }

        // 2) Máscara L–V, etc.
        $phpDow = (int)$d->format('N');
        $bit = match ($phpDow) {
            1 => 2,   // Mon
            2 => 4,   // Tue
            3 => 8,   // Wed
            4 => 16,  // Thu
            5 => 32,  // Fri
            6 => 64,  // Sat
            7 => 128, // Sun
            default => 0,
        };
        return (($this->workdaysMask & $bit) !== 0);
    }

    private function nextWorkdayStart(DateTimeImmutable $dt): DateTimeImmutable
    {
        $d = $dt->setTimezone($this->tz);

        // avanzar al siguiente día
        $d = $d->add(new DateInterval('P1D'));
        $d = $this->atTime($d, $this->startTime);

        while (!$this->isWorkday($d)) {
            $d = $d->add(new DateInterval('P1D'));
            $d = $this->atTime($d, $this->startTime);
        }
        return $d;
    }

    private function atTime(DateTimeImmutable $base, string $time): DateTimeImmutable
    {
        $parts = explode(':', $time);
        $h = (int)($parts[0] ?? 0);
        $m = (int)($parts[1] ?? 0);
        $s = (int)($parts[2] ?? 0);
        return $base->setTime($h, $m, $s);
    }
}
