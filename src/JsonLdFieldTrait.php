<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;

/**
 * Shared field-reading helpers for normalizers and contributors.
 *
 * Every helper enforces the two parity-safe rules from SCHEMA_MAP.md: a value
 * is read only when its field is (a) present in the rendered view display and
 * (b) non-empty. JSON-LD therefore never exceeds the visible HTML.
 */
trait JsonLdFieldTrait {

  /**
   * Whether a field is rendered by the given view display (parity guard #3).
   */
  protected function isVisible(EntityViewDisplayInterface $display, string $field): bool {
    return $display->getComponent($field) !== NULL;
  }

  /**
   * Whether a node has a visible, non-empty value for a field.
   */
  protected function hasValue(NodeInterface $node, EntityViewDisplayInterface $display, string $field): bool {
    return $this->isVisible($display, $field)
      && $node->hasField($field)
      && !$node->get($field)->isEmpty();
  }

  /**
   * Strip markup and collapse whitespace so no HTML leaks into a JSON string.
   */
  protected function plainText(string $raw): string {
    $text = strip_tags($raw);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
  }

  /**
   * Absolute canonical URL for an entity, bubbling its URL cacheability.
   */
  protected function absoluteUrl(EntityInterface $entity, JsonLdContext $context): string {
    $generated = $entity->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
    $context->cacheability->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

  /**
   * Labels of referenced taxonomy terms (visible, non-empty), bubbling tags.
   *
   * @return string[]
   */
  protected function referencedTermNames(NodeInterface $node, EntityViewDisplayInterface $display, string $field, JsonLdContext $context): array {
    if (!$this->hasValue($node, $display, $field)) {
      return [];
    }
    $names = [];
    foreach ($node->get($field)->referencedEntities() as $term) {
      $context->cacheability->addCacheableDependency($term);
      $label = trim((string) $term->label());
      if ($label !== '') {
        $names[] = $label;
      }
    }
    return $names;
  }

  /**
   * Resolve an entity_reference field of evidence_source nodes to citations.
   *
   * The cache tag for every referenced node is bubbled BEFORE the published
   * check, so unpublishing a source invalidates this page and drops the
   * (now dangling) citation. Only published targets become a citation @id, and
   * the @id matches the fragment EvidenceSourceNormalizer mints (#evidence-source).
   *
   * @return array<int, array{'@id': string}>
   */
  protected function resolveCitations(NodeInterface $node, EntityViewDisplayInterface $display, string $field, JsonLdContext $context): array {
    if (!$this->hasValue($node, $display, $field)) {
      return [];
    }
    $citations = [];
    foreach ($node->get($field)->referencedEntities() as $reference) {
      // Cross-entity invalidation: bubble the tag even for unpublished targets.
      $context->cacheability->addCacheableDependency($reference);
      if (!$reference instanceof NodeInterface || !$reference->isPublished()) {
        continue;
      }
      $citations[] = ['@id' => $this->absoluteUrl($reference, $context) . '#evidence-source'];
    }
    return $citations;
  }

  /**
   * Datetime field values are stored ISO 8601; pass through trimmed.
   */
  protected function isoDate(string $value): string {
    return trim($value);
  }

}
