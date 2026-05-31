<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld\Normalizer;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\node\NodeInterface;

/**
 * A per-bundle normalizer that maps a node to schema.org graph object(s).
 *
 * Implementations are registered as tagged services
 * (geo_starter_jsonld.node_normalizer) and collected by the graph builder.
 */
interface NodeNormalizerInterface {

  /**
   * Whether this normalizer handles the given node's bundle.
   */
  public function applies(NodeInterface $node): bool;

  /**
   * Build the schema.org object(s) for the node.
   *
   * The first returned object is treated as the page's primary entity (the
   * WebPage's mainEntity links to its @id). Return an empty array to emit no
   * primary entity.
   *
   * @return array<int, array<string, mixed>>
   */
  public function normalize(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array;

}
