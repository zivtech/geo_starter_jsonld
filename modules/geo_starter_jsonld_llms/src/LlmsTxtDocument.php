<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld_llms;

/**
 * Assembles an llms.txt markdown document from already-extracted primitives.
 *
 * Pure value object with zero Drupal dependencies, so the markdown grammar —
 * escaping, truncation, section omission, the required H1 + blockquote
 * structure (https://llmstxt.org) — is unit-testable without a bootstrap. The
 * builder hands in raw strings; every formatting and safety rule lives here.
 *
 * Safety rules, in order of application:
 * - collapse(): strip tags, decode entities, fold ALL whitespace (including
 *   newlines — a raw newline would terminate a list entry and corrupt the
 *   document) to single spaces. The same algorithm as the parent module's
 *   JsonLdFieldTrait::plainText(), duplicated deliberately: importing the
 *   JSON-LD trait for one five-line helper would couple this submodule to the
 *   parent's field-reading surface.
 * - Link text and descriptions backslash-escape `\`, `[` and `]` so a title
 *   like "Foo [bar]" cannot break or forge the [text](url) token.
 * - URLs are machine-generated canonical URLs; if one ever carries a paren,
 *   angle bracket, or whitespace it is wrapped in the CommonMark <...> form.
 * - Descriptions are truncated at a word boundary so a multi-paragraph
 *   direct answer cannot bloat what is meant to be a concise site map.
 */
final class LlmsTxtDocument {

  /**
   * Maximum description length in characters, cut at a word boundary.
   */
  private const DESCRIPTION_MAX_LENGTH = 200;

  /**
   * Constructs an LlmsTxtDocument.
   *
   * @param string $siteName
   *   The site name for the required H1. Blank falls back to "Site" so the
   *   heading line is never structurally empty.
   * @param string $summary
   *   The blockquote summary. The builder guarantees a non-empty value via
   *   its fallback chain; a blank summary degrades to omitting the
   *   blockquote rather than emitting a malformed "> " line.
   * @param array<int, array{title: string, entries: array<int, array{text: string, url: string, description: string}>}> $sections
   *   Ordered H2 sections. Entry text and description are raw field values;
   *   this object applies all collapsing, escaping, and truncation. Sections
   *   with zero entries are omitted entirely.
   */
  public function __construct(
    private readonly string $siteName,
    private readonly string $summary,
    private readonly array $sections,
  ) {}

  /**
   * Renders the markdown document, always ending in a single newline.
   */
  public function render(): string {
    $name = self::collapse($this->siteName);
    $lines = ['# ' . ($name === '' ? 'Site' : $name)];

    $summary = self::collapse($this->summary);
    if ($summary !== '') {
      $lines[] = '';
      $lines[] = '> ' . self::escapeBrackets($summary);
    }

    foreach ($this->sections as $section) {
      if ($section['entries'] === []) {
        continue;
      }
      $lines[] = '';
      $lines[] = '## ' . self::collapse($section['title']);
      foreach ($section['entries'] as $entry) {
        $lines[] = self::entryLine($entry);
      }
    }

    return implode("\n", $lines) . "\n";
  }

  /**
   * Formats one "- [text](url): description" list entry.
   *
   * @param array{text: string, url: string, description: string} $entry
   *   The raw entry primitives.
   */
  private static function entryLine(array $entry): string {
    $text = self::escapeBrackets(self::collapse($entry['text']));
    $line = '- [' . $text . '](' . self::destination($entry['url']) . ')';

    $description = self::truncate(self::collapse($entry['description']));
    if ($description !== '') {
      $line .= ': ' . self::escapeBrackets($description);
    }
    return $line;
  }

  /**
   * Strip markup and collapse all whitespace (including newlines) to spaces.
   */
  private static function collapse(string $raw): string {
    $text = strip_tags($raw);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
  }

  /**
   * Backslash-escape the characters that break a markdown [text] token.
   *
   * Backslashes are escaped first so the escapes added for brackets are not
   * themselves re-escaped.
   */
  private static function escapeBrackets(string $text): string {
    return str_replace(['\\', '[', ']'], ['\\\\', '\[', '\]'], $text);
  }

  /**
   * Format a link destination, angle-wrapping any markdown-unsafe URL.
   *
   * Drupal-generated canonical URLs are already percent-encoded and never
   * carry raw parens or whitespace in practice; the guard is cheap insurance.
   */
  private static function destination(string $url): string {
    if (preg_match('/[\s()<>]/', $url) !== 1) {
      return $url;
    }
    return '<' . str_replace(['<', '>'], ['%3C', '%3E'], $url) . '>';
  }

  /**
   * Truncate collapsed text at a word boundary, appending an ellipsis.
   */
  private static function truncate(string $text): string {
    if (mb_strlen($text) <= self::DESCRIPTION_MAX_LENGTH) {
      return $text;
    }
    $cut = mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH);
    $space = mb_strrpos($cut, ' ');
    if ($space !== FALSE && $space > 0) {
      $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, " \t.,;:") . '…';
  }

}
