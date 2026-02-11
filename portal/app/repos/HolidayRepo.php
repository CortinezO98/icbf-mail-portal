<?php
declare(strict_types=1);

namespace App\Repos;

use DateTimeImmutable;
use PDO;

final class HolidayRepo
{
    public function __construct(private PDO $pdo) {}

    public function isHoliday(string $countryCode, DateTimeImmutable $dt): bool
    {
        $date = $dt->format('Y-m-d');
        $st = $this->pdo->prepare("
            SELECT 1
            FROM holiday_calendar
            WHERE country_code = :cc AND holiday_date = :d
            LIMIT 1
        ");
        $st->execute([':cc' => $countryCode, ':d' => $date]);
        return (bool)$st->fetchColumn();
    }
}
