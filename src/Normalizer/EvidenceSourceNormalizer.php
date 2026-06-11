<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld\Normalizer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\geo_starter_jsonld\JsonLdFieldTrait;
use Drupal\node\NodeInterface;

/**
 * Normalizes an Evidence Source node to a schema.org CreativeWork.
 *
 * This is the resolution target for citations (jsonld plan §2.4 / §4): a
 * citing node emits citation @id = {evidence_url}#evidence-source, and this
 * normalizer declares the full CreativeWork at exactly that @id. Without it
 * those citation @ids would dangle — a provenance break in a provenance-
 * positioned product, invisible to validators that do not follow @ids.
 */
final class EvidenceSourceNormalizer implements NodeNormalizerInterface {

  use JsonLdFieldTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node): bool {
    return $node->bundle() === 'evidence_source';
  }

  /**
   * {@inheritdoc}
   */
  public function normalize(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
    $settings = $this->configFactory->get('geo_starter_jsonld.settings');

    $creative_work = [
      '@type' => $settings->get('evidence_default_type') ?: 'CreativeWork',
      '@id' => $context->canonicalUrl . '#evidence-source',
      'name' => $node->label(),
    ];

    // The external source link is asserted exactly once, here at the source, as
    // both url and sameAs (matching the visibly rendered source link).
    if ($this->hasValue($node, $display, 'field_source_url')) {
      try {
        $source = $node->get('field_source_url')->first()->getUrl()->setAbsolute()->toString();
      }
      catch (\InvalidArgumentException $e) {
        // A stored URI the Url factory rejects (migrated/programmatic content
        // bypasses widget validation). Emit the CreativeWork without url/sameAs
        // rather than failing the whole page render.
        $source = '';
      }
      if ($source !== '') {
        $creative_work['url'] = $source;
        $creative_work['sameAs'] = $source;
      }
    }

    if ($this->hasValue($node, $display, 'field_publisher')) {
      $publisher = $this->plainText((string) $node->get('field_publisher')->value);
      if ($publisher !== '') {
        $creative_work['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
      }
    }

    return [$creative_work];
  }

}
