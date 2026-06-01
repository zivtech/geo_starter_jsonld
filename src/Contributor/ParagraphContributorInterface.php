<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld\Contributor;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\node\NodeInterface;

/**
 * Contributes paragraph-derived top-level graph objects (e.g. FAQPage, HowTo).
 *
 * Implementations are registered as tagged services
 * (geo_starter_jsonld.paragraph_contributor) and collected by the graph
 * builder.
 */
interface ParagraphContributorInterface {

  /**
   * Whether this contributor may emit for the given node.
   */
  public function applies(NodeInterface $node, EntityViewDisplayInterface $display): bool;

  /**
   * Build zero or more top-level graph objects to merge into the @graph.
   *
   * Returns an empty array whenever a content-based gate fails (e.g. fewer than
   * two valid FAQ pairs) — the page then renders its visible HTML with no
   * structured object, which is parity-safe.
   *
   * @return array<int, array<string, mixed>>
   *   Zero or more top-level graph objects to merge into the @graph.
   */
  public function contribute(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array;

}
