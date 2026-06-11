<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld_llms\Unit;

use Drupal\geo_starter_jsonld_llms\LlmsTxtDocument;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-tests the llms.txt markdown grammar in isolation.
 *
 * LlmsTxtDocument carries every formatting and safety rule of the document —
 * the required H1 + blockquote shape, bracket escaping, newline collapsing,
 * word-boundary truncation, and empty-section omission. It has zero Drupal
 * dependencies precisely so these guarantees are provable here without a
 * bootstrap; the Kernel suite then only has to prove the builder feeds it
 * the right data.
 */
#[CoversClass(LlmsTxtDocument::class)]
#[Group('geo_starter_jsonld_llms')]
final class LlmsTxtDocumentTest extends UnitTestCase {

  /**
   * Builds a document with one "Services" section of the given entries.
   *
   * @param array<int, array{text: string, url: string, description: string}> $entries
   *   The section's entries.
   */
  private static function doc(array $entries = []): LlmsTxtDocument {
    return new LlmsTxtDocument(
      'Acme Health',
      'Plain help for hard situations.',
      [['title' => 'Services', 'entries' => $entries]],
    );
  }

  /**
   * The spec-required shape: H1 first, blockquote second, then sections.
   */
  public function testRequiredStructure(): void {
    $markdown = self::doc([
      ['text' => 'Emergency help', 'url' => 'https://example.com/emergency', 'description' => ''],
    ])->render();

    $expected = "# Acme Health\n"
      . "\n"
      . "> Plain help for hard situations.\n"
      . "\n"
      . "## Services\n"
      . "- [Emergency help](https://example.com/emergency)\n";
    $this->assertSame($expected, $markdown);
  }

  /**
   * Square brackets and backslashes in titles cannot break the link token.
   */
  public function testEscapesLinkBrackets(): void {
    $markdown = self::doc([
      ['text' => 'Foo [bar] (baz) \\ qux', 'url' => 'https://example.com/a', 'description' => ''],
    ])->render();

    $this->assertStringContainsString(
      '- [Foo \[bar\] (baz) \\\\ qux](https://example.com/a)',
      $markdown,
    );
  }

  /**
   * A URL carrying a paren is angle-wrapped (CommonMark destination form).
   */
  public function testUrlWithParensIsAngleWrapped(): void {
    $markdown = self::doc([
      ['text' => 'Odd path', 'url' => 'https://example.com/a(b)', 'description' => ''],
    ])->render();

    $this->assertStringContainsString('- [Odd path](<https://example.com/a(b)>)', $markdown);
  }

  /**
   * Newlines and markup in descriptions collapse to one clean line.
   *
   * A raw newline would terminate the list entry and corrupt the next line,
   * so this is the document's most load-bearing safety rule.
   */
  public function testDescriptionCollapsesNewlinesAndMarkup(): void {
    $markdown = self::doc([
      [
        'text' => 'Answer page',
        'url' => 'https://example.com/q',
        'description' => "<p>Line one.</p>\nLine [two] &amp; more.\n",
      ],
    ])->render();

    $this->assertStringContainsString(
      '- [Answer page](https://example.com/q): Line one. Line \[two\] & more.',
      $markdown,
    );
    $this->assertSame(6, substr_count($markdown, "\n"), 'The entry stays on a single line.');
  }

  /**
   * Long descriptions truncate at a word boundary with a single ellipsis.
   */
  public function testDescriptionTruncatesAtWordBoundary(): void {
    $description = trim(str_repeat('wordseven ', 40));
    $markdown = self::doc([
      ['text' => 'Long answer', 'url' => 'https://example.com/q', 'description' => $description],
    ])->render();

    preg_match('/: (.+)$/m', $markdown, $matches);
    $emitted = $matches[1];
    $this->assertStringEndsWith('…', $emitted);
    $this->assertLessThanOrEqual(201, mb_strlen($emitted));
    // Word-boundary cut: everything before the ellipsis is whole words.
    $this->assertMatchesRegularExpression('/^(wordseven )*wordseven…$/', $emitted);
  }

  /**
   * A section with zero entries emits no heading at all.
   */
  public function testEmptySectionIsOmitted(): void {
    $markdown = self::doc([])->render();

    $this->assertStringNotContainsString('##', $markdown);
    $this->assertSame("# Acme Health\n\n> Plain help for hard situations.\n", $markdown);
  }

  /**
   * An empty description emits a bare link with no trailing colon.
   */
  public function testEmptyDescriptionOmitsColon(): void {
    $markdown = self::doc([
      ['text' => 'Bare entry', 'url' => 'https://example.com/a', 'description' => '  '],
    ])->render();

    $this->assertStringContainsString("- [Bare entry](https://example.com/a)\n", $markdown);
    $this->assertStringNotContainsString('](https://example.com/a):', $markdown);
  }

  /**
   * Markup and entities in the site name and titles are collapsed.
   */
  public function testCollapsesMarkupInNameAndTitle(): void {
    $document = new LlmsTxtDocument(
      "<em>Acme</em>\n&amp; Co",
      'Summary.',
      [
        [
          'title' => 'Services',
          'entries' => [
            ['text' => "Help  with\nforms &nbsp;now", 'url' => 'https://example.com/f', 'description' => ''],
          ],
        ],
      ],
    );
    $markdown = $document->render();

    $this->assertStringContainsString('# Acme & Co', $markdown);
    $this->assertStringContainsString('- [Help with forms now](https://example.com/f)', $markdown);
  }

  /**
   * Structural guards: blank name falls back, blank summary drops the quote.
   */
  public function testBlankNameAndSummaryGuards(): void {
    $markdown = (new LlmsTxtDocument('  ', '', []))->render();

    $this->assertSame("# Site\n", $markdown);
    $this->assertStringNotContainsString('>', $markdown);
  }

}
