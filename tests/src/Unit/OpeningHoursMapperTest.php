<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Unit;

use Drupal\geo_starter_jsonld\OpeningHoursMapper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-tests the pure office_hours-to-OpeningHoursSpecification mapper.
 *
 * The "monday real getValue shape" case and the four midnight/overnight cases
 * are pinned to what office_hours actually stores at runtime (verified on a
 * real field) — the recipe's own Mon-Fri 09:00-17:00 sample never exercises
 * those edges, so they are covered here. Cases marked DEFENSIVE (all_day,
 * closed-via-null) are belt-and-suspenders for inputs office_hours is unlikely
 * to produce.
 */
#[CoversClass(OpeningHoursMapper::class)]
#[Group('geo_starter_jsonld')]
final class OpeningHoursMapperTest extends UnitTestCase {

  /**
   * Formats integer HHMM times to zero-padded "HH:MM", clamping past 24:00.
   */
  #[DataProvider('formatTimeProvider')]
  public function testFormatTime(int $input, string $expected): void {
    $this->assertSame($expected, OpeningHoursMapper::formatTime($input));
  }

  /**
   * Cases for ::formatTime.
   *
   * @return array<string, array{int, string}>
   *   Integer HHMM mapped to its expected "HH:MM" string.
   */
  public static function formatTimeProvider(): array {
    return [
      'morning zero-pads the hour' => [900, '09:00'],
      'half past keeps the minutes' => [930, '09:30'],
      'afternoon' => [1700, '17:00'],
      'top of the first hour' => [100, '01:00'],
      'midnight' => [0, '00:00'],
      'end of day' => [2359, '23:59'],
      'exactly 24:00 clamps (DEFENSIVE)' => [2400, '23:59'],
      'past-midnight close clamps (DEFENSIVE)' => [2600, '23:59'],
    ];
  }

  /**
   * Maps office_hours day indices (0=Sunday) to schema.org DayOfWeek IRIs.
   */
  #[DataProvider('dayOfWeekProvider')]
  public function testDayOfWeekIri(int $day, ?string $expected): void {
    $this->assertSame($expected, OpeningHoursMapper::dayOfWeekIri($day));
  }

  /**
   * Cases for ::dayOfWeekIri.
   *
   * @return array<string, array{int, string|null}>
   *   Day index mapped to its expected IRI, or NULL when out of range.
   */
  public static function dayOfWeekProvider(): array {
    return [
      'sunday is 0' => [0, 'https://schema.org/Sunday'],
      'monday is 1' => [1, 'https://schema.org/Monday'],
      'friday is 5' => [5, 'https://schema.org/Friday'],
      'saturday is 6' => [6, 'https://schema.org/Saturday'],
      'seven is out of range' => [7, NULL],
      'negative is out of range' => [-1, NULL],
      'exception date int is out of range' => [20260715, NULL],
    ];
  }

  /**
   * Maps whole office_hours field values to OpeningHoursSpecification lists.
   */
  #[DataProvider('mapRowsProvider')]
  public function testMapRows(array $rows, array $expected): void {
    $this->assertSame($expected, OpeningHoursMapper::mapRows($rows));
  }

  /**
   * Cases for ::mapRows.
   *
   * @return array<string, array{array, array}>
   *   Office_hours rows mapped to the expected specification list.
   */
  public static function mapRowsProvider(): array {
    return [
      'empty field yields nothing' => [[], []],

      'monday real getValue shape ignores day_delta and comment' => [
        [
          [
            'day' => 1,
            'day_delta' => 0,
            'all_day' => FALSE,
            'starthours' => 900,
            'endhours' => 1700,
            'comment' => '',
          ],
        ],
        [self::spec('Monday', '09:00', '17:00')],
      ],

      'full monday-to-friday sample' => [
        [
          ['day' => 1, 'starthours' => 900, 'endhours' => 1700],
          ['day' => 2, 'starthours' => 900, 'endhours' => 1700],
          ['day' => 3, 'starthours' => 900, 'endhours' => 1700],
          ['day' => 4, 'starthours' => 900, 'endhours' => 1700],
          ['day' => 5, 'starthours' => 900, 'endhours' => 1700],
        ],
        [
          self::spec('Monday', '09:00', '17:00'),
          self::spec('Tuesday', '09:00', '17:00'),
          self::spec('Wednesday', '09:00', '17:00'),
          self::spec('Thursday', '09:00', '17:00'),
          self::spec('Friday', '09:00', '17:00'),
        ],
      ],

      'half-hour boundaries' => [
        [['day' => 1, 'starthours' => 930, 'endhours' => 1730]],
        [self::spec('Monday', '09:30', '17:30')],
      ],

      'multi-slot day yields two specifications' => [
        [
          ['day' => 1, 'starthours' => 900, 'endhours' => 1200],
          ['day' => 1, 'starthours' => 1300, 'endhours' => 1700],
        ],
        [
          self::spec('Monday', '09:00', '12:00'),
          self::spec('Monday', '13:00', '17:00'),
        ],
      ],

      'all_day wins over zeroed hours (DEFENSIVE)' => [
        [['day' => 3, 'all_day' => TRUE, 'starthours' => 0, 'endhours' => 0]],
        [self::spec('Wednesday', '00:00', '23:59')],
      ],

      // The next four are pinned to what office_hours actually STORES (verified
      // on a real field), not assumed shapes: a midnight close is endhours 0
      // (the table renders it "24:00"), and overnight is endhours < starthours.
      'midnight close (endhours 0) becomes end of day' => [
        [['day' => 1, 'starthours' => 900, 'endhours' => 0]],
        [self::spec('Monday', '09:00', '23:59')],
      ],

      'late slot closing at midnight (endhours 0)' => [
        [['day' => 1, 'starthours' => 2200, 'endhours' => 0]],
        [self::spec('Monday', '22:00', '23:59')],
      ],

      'midnight OPEN (starthours 0) keeps 00:00' => [
        [['day' => 2, 'starthours' => 0, 'endhours' => 1700]],
        [self::spec('Tuesday', '00:00', '17:00')],
      ],

      'overnight slot (endhours < starthours) is dropped' => [
        [['day' => 5, 'starthours' => 2200, 'endhours' => 200]],
        [],
      ],

      'closed day with null hours is skipped (DEFENSIVE)' => [
        [['day' => 0, 'all_day' => FALSE, 'starthours' => NULL, 'endhours' => NULL]],
        [],
      ],

      'row missing the day key is skipped' => [
        [['starthours' => 900, 'endhours' => 1700]],
        [],
      ],

      'row missing hours and not all-day is skipped' => [
        [['day' => 1]],
        [],
      ],

      'zero-length window is skipped' => [
        [['day' => 2, 'starthours' => 900, 'endhours' => 900]],
        [],
      ],

      'out-of-range exception row is skipped' => [
        [['day' => 20260715, 'starthours' => 900, 'endhours' => 1700]],
        [],
      ],

      'malformed rows are skipped but valid ones survive' => [
        [
          ['day' => 1, 'starthours' => 900, 'endhours' => 1700],
          'not-an-array',
          ['day' => 1],
          ['day' => 3, 'starthours' => 1000, 'endhours' => 1600],
        ],
        [
          self::spec('Monday', '09:00', '17:00'),
          self::spec('Wednesday', '10:00', '16:00'),
        ],
      ],
    ];
  }

  /**
   * Builds an expected OpeningHoursSpecification in the mapper's key order.
   *
   * @param string $dayLabel
   *   The schema.org DayOfWeek label, e.g. "Monday".
   * @param string $opens
   *   The expected "HH:MM" open time.
   * @param string $closes
   *   The expected "HH:MM" close time.
   *
   * @return array<string, string>
   *   The expected specification array.
   */
  private static function spec(string $dayLabel, string $opens, string $closes): array {
    return [
      '@type' => 'OpeningHoursSpecification',
      'dayOfWeek' => 'https://schema.org/' . $dayLabel,
      'opens' => $opens,
      'closes' => $closes,
    ];
  }

}
