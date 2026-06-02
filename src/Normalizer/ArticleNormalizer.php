<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld\Normalizer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\geo_starter_jsonld\JsonLdFieldTrait;
use Drupal\node\NodeInterface;

/**
 * Normalizes an Article node to a schema.org Article (jsonld plan §2.3).
 *
 * Author and reviewer are two DISTINCT fields and must not be conflated:
 * author ← field_author_name, reviewedBy/review ← field_reviewed_by_name.
 */
final class ArticleNormalizer implements NodeNormalizerInterface {

  use JsonLdFieldTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node): bool {
    return $node->bundle() === 'article';
  }

  /**
   * {@inheritdoc}
   */
  public function normalize(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
    $settings = $this->configFactory->get('geo_starter_jsonld.settings');

    $article = [
      '@type' => $settings->get('article_type') ?: 'Article',
      '@id' => $context->canonicalUrl . '#article',
      'headline' => $node->label(),
    ];

    if ($this->hasValue($node, $display, 'field_summary')) {
      $description = $this->plainText((string) $node->get('field_summary')->value);
      if ($description !== '') {
        $article['description'] = $description;
      }
    }

    // Author comes from the dedicated author field — NOT the reviewer.
    if ($this->hasValue($node, $display, 'field_author_name')) {
      $author = $this->plainText((string) $node->get('field_author_name')->value);
      if ($author !== '') {
        $article['author'] = ['@type' => 'Person', 'name' => $author];
      }
    }

    // Reviewer is distinct from author (field_reviewed_by_name). reviewedBy is
    // WebPage-domain-only (not CreativeWork), so even on an Article it lives on
    // the WebPage; its paired review rides along to keep them on one node. The
    // remaining CreativeWork properties below (about, citation, dateModified,
    // datePublished) are domain-valid on Article and stay here.
    foreach ($this->schemaReviewedBy($node, $display, $context) as $property => $value) {
      $context->addWebPageProperty($property, $value);
    }

    if ($this->hasValue($node, $display, 'field_reviewed_date')) {
      $article['dateModified'] = $this->isoDate((string) $node->get('field_reviewed_date')->value);
    }

    $article['datePublished'] = $this->datePublished($node);

    $citations = $this->resolveCitations($node, $display, 'field_evidence_sources', $context);
    if ($citations !== []) {
      $article['citation'] = $citations;
    }

    $about = $this->schemaAbout($node, $display, 'field_topic', $context);
    if ($about !== []) {
      $article['about'] = $about;
    }
    $audience = $this->schemaAudience($node, $display, 'field_audience', $context);
    if ($audience !== []) {
      $article['audience'] = $audience;
    }

    return [$article];
  }

  /**
   * Resolves the most accurate publication date for the node.
   *
   * Prefers a first-published timestamp (published_at, if a module provides
   * it) and falls back to the node's created time.
   */
  private function datePublished(NodeInterface $node): string {
    if ($node->hasField('published_at') && !$node->get('published_at')->isEmpty()) {
      $value = $node->get('published_at')->value;
      if (is_numeric($value)) {
        return $this->isoFromTimestamp((int) $value);
      }
      if (is_string($value) && $value !== '') {
        return $this->isoDate($value);
      }
    }
    return $this->isoFromTimestamp((int) $node->getCreatedTime());
  }

}
