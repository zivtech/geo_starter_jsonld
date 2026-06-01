<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Unit;

use Drupal\geo_starter_jsonld\JsonLdFieldTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-tests the pure, state-free helpers in JsonLdFieldTrait.
 *
 * These three helpers carry the JSON-LD parity/sanitisation guarantees: text is
 * stripped of markup before it can enter a JSON string, and dates are emitted
 * in a stable ISO 8601 (UTC) shape. They touch no entity/container state, so
 * they are tested in isolation with an anonymous class that exposes them
 * publicly.
 */
#[CoversClass(JsonLdFieldTrait::class)]
#[Group('geo_starter_jsonld')]
final class JsonLdFieldTraitTest extends UnitTestCase {

  /**
   * Subject exposing the trait's protected helpers as public methods.
   */
  private object $subject;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->subject = new class() {
      use JsonLdFieldTrait {
        plainText as public;
        isoDate as public;
        isoFromTimestamp as public;
      }
    };
  }

  /**
   * Strips markup, decodes entities, and collapses whitespace via plainText().
   */
  #[DataProvider('plainTextProvider')]
  public function testPlainText(string $raw, string $expected): void {
    $this->assertSame($expected, $this->subject->plainText($raw));
  }

  /**
   * Cases for ::plainText.
   *
   * @return array<string, array{string, string}>
   *   Raw input mapped to its expected plain-text output.
   */
  public static function plainTextProvider(): array {
    return [
      'strips block + inline tags' => ['<p>Hello <strong>world</strong></p>', 'Hello world'],
      'collapses runs of whitespace' => ["First\n\n  second\tthird", 'First second third'],
      'decodes named + amp entities' => ['Caf&eacute; &amp; tea', 'Café & tea'],
      'decodes numeric entities' => ['5 &#37; off', '5 % off'],
      'trims leading/trailing space' => ['   padded   ', 'padded'],
      'empty string stays empty' => ['', ''],
      'whitespace-only collapses to empty' => ["  \n\t ", ''],
      'unicode is preserved' => ['Köln — naïve', 'Köln — naïve'],
    ];
  }

  /**
   * Formats a Unix timestamp as UTC Zulu ISO 8601 via isoFromTimestamp().
   */
  public function testIsoFromTimestampUsesUtcZuluFormat(): void {
    $this->assertSame('1970-01-01T00:00:00Z', $this->subject->isoFromTimestamp(0));
    // 1700000000 == 2023-11-14T22:13:20 UTC.
    $this->assertSame('2023-11-14T22:13:20Z', $this->subject->isoFromTimestamp(1700000000));
  }

  /**
   * Trims surrounding whitespace without reformatting the value (isoDate()).
   */
  public function testIsoDateTrimsButDoesNotReformat(): void {
    $this->assertSame('2024-01-02T10:30:00', $this->subject->isoDate('  2024-01-02T10:30:00  '));
    $this->assertSame('2024-01-02', $this->subject->isoDate('2024-01-02'));
  }

}
