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

  public function __construct(
    public readonly string $canonicalUrl,
    public readonly CacheableMetadata $cacheability,
  ) {}

}
