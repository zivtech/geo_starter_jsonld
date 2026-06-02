<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld;

/**
 * Maps office_hours field rows to schema.org OpeningHoursSpecification arrays.
 *
 * Pure and stateless: no Drupal dependencies, so the module never takes a hard
 * dependency on the office_hours contrib module. It reads only the plain value
 * columns office_hours stores (day, starthours, endhours, all_day) and ignores
 * everything else (comment, day_delta, season/exception metadata). Anything it
 * cannot map faithfully is dropped, never fabricated — a wrong property is a
 * worse GEO signal than an absent one.
 *
 * office_hours stores each weekly slot as:
 *   - day:        int 0-6 (0=Sunday … 6=Saturday, per date('w'))
 *   - starthours: int HHMM (e.g. 900 = 09:00, 1730 = 17:30)
 *   - endhours:   int HHMM
 *   - all_day:    bool
 * Date-specific exception/season rows use out-of-range day values and are
 * skipped: this maps weekly recurring hours only.
 */
final class OpeningHoursMapper {

  /**
   * Weekday index (0=Sunday) to schema.org DayOfWeek label.
   */
  private const DAYS = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
  ];

  /**
   * Maps office_hours value rows to OpeningHoursSpecification arrays.
   *
   * @param array $rows
   *   The office_hours field value, one row per slot. Extra keys are ignored;
   *   malformed, closed, comment-only, overnight, and zero-length rows are
   *   skipped (they render in the HTML table but have no faithful
   *   OpeningHoursSpecification, so an absent value beats a wrong one).
   *
   * @return array<int, array<string, string>>
   *   One OpeningHoursSpecification per usable slot, in field order, or [].
   */
  public static function mapRows(array $rows): array {
    $specifications = [];
    foreach ($rows as $row) {
      $specification = self::mapRow(is_array($row) ? $row : []);
      if ($specification !== NULL) {
        $specifications[] = $specification;
      }
    }
    return $specifications;
  }

  /**
   * Maps an office_hours day index (0=Sunday) to a schema.org DayOfWeek IRI.
   *
   * @param int $day
   *   The stored day index.
   *
   * @return string|null
   *   The full schema.org IRI, or NULL when the index is not a weekday 0-6.
   */
  public static function dayOfWeekIri(int $day): ?string {
    if (!isset(self::DAYS[$day])) {
      return NULL;
    }
    return 'https://schema.org/' . self::DAYS[$day];
  }

  /**
   * Formats an office_hours integer time (HHMM) as a schema.org "HH:MM" Time.
   *
   * @param int $time
   *   The integer time, e.g. 900 (09:00) or 1730 (17:30).
   *
   * @return string
   *   A zero-padded 24-hour "HH:MM" string. Values at or past 24:00 clamp to
   *   "23:59" (mapRow normalizes a midnight close to 2400 before calling this):
   *   schema.org Time is a wall-clock time, not a duration.
   */
  public static function formatTime(int $time): string {
    if ($time >= 2400) {
      return '23:59';
    }
    if ($time < 0) {
      $time = 0;
    }
    $minutes = $time % 100;
    if ($minutes > 59) {
      $minutes = 59;
    }
    return sprintf('%02d:%02d', intdiv($time, 100), $minutes);
  }

  /**
   * Maps a single office_hours row, or NULL when it cannot be mapped.
   *
   * @param array $row
   *   A single office_hours value row.
   *
   * @return array<string, string>|null
   *   The OpeningHoursSpecification, or NULL to skip the row.
   */
  private static function mapRow(array $row): ?array {
    if (!isset($row['day']) || !is_numeric($row['day'])) {
      return NULL;
    }
    $day = self::dayOfWeekIri((int) $row['day']);
    if ($day === NULL) {
      // Exception/season rows (out-of-range day) are not weekly hours.
      return NULL;
    }

    if (!empty($row['all_day'])) {
      $opens = '00:00';
      $closes = '23:59';
    }
    else {
      $start = $row['starthours'] ?? NULL;
      $end = $row['endhours'] ?? NULL;
      if (!self::isPresentTime($start) || !self::isPresentTime($end)) {
        // Closed day (no usable hours) — emit nothing rather than fabricate.
        return NULL;
      }
      $start = (int) $start;
      $end = (int) $end;
      // office_hours stores a midnight CLOSE as 0 (00:00 the next day) and the
      // visible table renders it "24:00". Treat it as end-of-day so the window
      // does not close before it opens. A midnight OPEN (start 0) is untouched.
      if ($end === 0) {
        $end = 2400;
      }
      // A genuine overnight slot (closes the following day, e.g. 22:00-02:00)
      // cannot be expressed as one OpeningHoursSpecification — drop it rather
      // than emit a backwards window. An absent value beats a wrong one.
      if ($end < $start) {
        return NULL;
      }
      $opens = self::formatTime($start);
      $closes = self::formatTime($end);
    }

    if ($opens === $closes) {
      // A zero-length window carries no information.
      return NULL;
    }

    return [
      '@type' => 'OpeningHoursSpecification',
      'dayOfWeek' => $day,
      'opens' => $opens,
      'closes' => $closes,
    ];
  }

  /**
   * Whether an office_hours time column holds a value.
   *
   * The office_hours module stores NULL or '' for an unset time; 0 is a real
   * time (midnight), so it counts as present.
   *
   * @param mixed $value
   *   The raw starthours/endhours value.
   *
   * @return bool
   *   TRUE when the column carries a numeric time.
   */
  private static function isPresentTime(mixed $value): bool {
    return $value !== NULL && $value !== '' && is_numeric($value);
  }

}
