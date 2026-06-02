<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\node\NodeInterface;

/**
 * Builds a single schema.org @graph for a node and returns it as JSON.
 *
 * Orchestration only: it applies the universal published guard, assembles the
 * base WebPage, delegates the primary entity to a per-bundle normalizer and any
 * paragraph-derived objects to contributors, collects cache metadata, and
 * encodes the result. All field reading lives in the normalizers/contributors.
 */
final class JsonLdGraphBuilder {

  /**
   * JSON flags for safely embedding the payload in a <script> element.
   *
   * Hex-escapes tags/ampersands so the payload cannot break out of the
   * <script> element, while keeping slashes and unicode human-readable.
   */
  private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

  /**
   * Constructs a JsonLdGraphBuilder.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read per-bundle emission settings.
   * @param \Drupal\geo_starter_jsonld\Normalizer\NodeNormalizerInterface[] $normalizers
   *   The tagged per-bundle node normalizers.
   * @param \Drupal\geo_starter_jsonld\Contributor\ParagraphContributorInterface[] $contributors
   *   The tagged paragraph-derived object contributors.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly iterable $normalizers,
    private readonly iterable $contributors,
  ) {}

  /**
   * Build the JSON-LD document for a node, or NULL when nothing should emit.
   *
   * @return array{json: string, cacheability: \Drupal\Core\Cache\CacheableMetadata}|null
   *   The encoded JSON-LD payload and its cache metadata, or NULL when the
   *   node is unpublished or no object was produced.
   */
  public function build(NodeInterface $node, EntityViewDisplayInterface $display): ?array {
    // Universal guard #1: published nodes only.
    if (!$node->isPublished()) {
      return NULL;
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($node);
    $cacheability->addCacheableDependency($display);
    $cacheability->addCacheableDependency($this->configFactory->get('geo_starter_jsonld.settings'));
    // @ids embed the absolute canonical URL, which varies by path alias.
    $cacheability->addCacheContexts(['url.path']);

    $generatedUrl = $node->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
    $cacheability->addCacheableDependency($generatedUrl);
    $context = new JsonLdContext($generatedUrl->getGeneratedUrl(), $cacheability);

    $graph = [];
    $primaryId = NULL;

    foreach ($this->normalizers as $normalizer) {
      if (!$normalizer->applies($node)) {
        continue;
      }
      foreach ($normalizer->normalize($node, $display, $context) as $object) {
        if (!empty($object)) {
          $graph[] = $object;
          if ($primaryId === NULL && isset($object['@id'])) {
            $primaryId = $object['@id'];
          }
        }
      }
      // One primary normalizer per bundle.
      break;
    }

    foreach ($this->contributors as $contributor) {
      if (!$contributor->applies($node, $display)) {
        continue;
      }
      foreach ($contributor->contribute($node, $display, $context) as $object) {
        if (!empty($object)) {
          $graph[] = $object;
        }
      }
    }

    // The WebPage is the spine of the graph: every page emits it, and it links
    // to the primary entity by @id so the graph is connected, not flat.
    $webPage = [
      '@type' => 'WebPage',
      '@id' => $context->canonicalUrl,
      'url' => $context->canonicalUrl,
      'name' => $node->label(),
    ];
    if ($primaryId !== NULL) {
      $webPage['mainEntity'] = ['@id' => $primaryId];
    }
    // Page-level properties a normalizer routed here (schema.org domain belongs
    // on the page, not the primary entity — e.g. a Service's reviewedBy). The
    // spine keys above always win; an empty bag is a no-op.
    $webPage += $context->webPageProperties;
    array_unshift($graph, $webPage);

    $document = [
      '@context' => 'https://schema.org',
      '@graph' => $graph,
    ];

    $json = json_encode($document, self::JSON_FLAGS);
    if ($json === FALSE) {
      // Universal guard #4: fail closed on any encode error.
      return NULL;
    }

    return ['json' => $json, 'cacheability' => $cacheability];
  }

}
