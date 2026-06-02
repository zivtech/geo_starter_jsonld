<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * Per-node build context shared between the builder and its contributors.
 *
 * Carries the node's absolute canonical URL (the root for all minted @ids) and
 * a single mutable CacheableMetadata accumulator. Normalizers and contributors
 * add cache tags/contexts for every entity they load so cross-entity changes
 * (e.g. an unpublished evidence source) invalidate the emitting page.
 */
final class JsonLdContext {

  /**
   * Page-level (WebPage) properties contributed by normalizers.
   *
   * Some schema.org properties an editor attaches to the primary entity
   * belong, by schema.org domain, on the page instead. A Service is not a
   * CreativeWork, so page metadata such as `about`, `citation` or
   * `dateModified` is domain-valid only on the WebPage; `reviewedBy` is
   * WebPage-domain-only for every bundle. Normalizers route those properties
   * here and the builder merges them onto the WebPage spine, keeping all field
   * reading in the normalizers. Keyed by schema.org property name.
   *
   * @var array<string, mixed>
   */
  public array $webPageProperties = [];

  public function __construct(
    public readonly string $canonicalUrl,
    public readonly CacheableMetadata $cacheability,
  ) {}

  /**
   * Routes a page-level property onto the WebPage, skipping empty values.
   *
   * Parity-safe: an empty value (NULL, '', or []) is never emitted, matching
   * the normalizers' "absent beats wrong" rule. Each property is set once per
   * build; a later non-empty value for the same key wins.
   *
   * @param string $property
   *   The schema.org property name (e.g. 'about', 'reviewedBy').
   * @param mixed $value
   *   The already-built property value.
   */
  public function addWebPageProperty(string $property, mixed $value): void {
    if ($value === NULL || $value === '' || $value === []) {
      return;
    }
    $this->webPageProperties[$property] = $value;
  }

}
