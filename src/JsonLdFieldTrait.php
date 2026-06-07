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
   *   The trimmed, non-empty term labels in field order.
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
   * the @id matches the fragment EvidenceSourceNormalizer mints
   * (#evidence-source).
   *
   * @return array<int, array{'@id': string}>
   *   Citation references keyed by @id, one per published evidence source.
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

  /**
   * ISO 8601 (UTC) from a Unix timestamp, e.g. a node's created time.
   */
  protected function isoFromTimestamp(int $timestamp): string {
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
  }

  /**
   * Schema.org `about` Things from a visible taxonomy field, or [].
   *
   * @return array<int, array{'@type': string, name: string}>
   *   A Thing object per referenced term, or an empty array.
   */
  protected function schemaAbout(NodeInterface $node, EntityViewDisplayInterface $display, string $field, JsonLdContext $context): array {
    return array_map(
      static fn (string $name): array => ['@type' => 'Thing', 'name' => $name],
      $this->referencedTermNames($node, $display, $field, $context),
    );
  }

  /**
   * Schema.org `audience` Audiences from a visible taxonomy field, or [].
   *
   * @return array<int, array{'@type': string, audienceType: string}>
   *   An Audience object per referenced term, or an empty array.
   */
  protected function schemaAudience(NodeInterface $node, EntityViewDisplayInterface $display, string $field, JsonLdContext $context): array {
    return array_map(
      static fn (string $name): array => ['@type' => 'Audience', 'audienceType' => $name],
      $this->referencedTermNames($node, $display, $field, $context),
    );
  }

  /**
   * Builds `reviewedBy` from the field_reviewed_by_name field.
   *
   * Conservative per the GEO trust mapping: emits only a Person name (we have
   * a name string, not an identity — never a fabricated @id or sameAs).
   * Returns an associative array to merge into the entity object, or [] when
   * there is no reviewer.
   *
   * Emits `reviewedBy` only — NOT a paired `review` (Review) object. A bare
   * `Review` with no `reviewRating` is a valid schema but an INVALID Google
   * Review-snippet rich result ("Missing field reviewRating"), flagged on
   * every Service/Answer/Article page by the Rich Results Test (WS-D Phase 1,
   * 2026-06-07). The intent is provenance ("reviewed by X on date Y"), fully
   * carried by `reviewedBy` (person) plus the `dateModified` each normalizer
   * already emits on its primary entity — there is no rating, so no `Review`.
   *
   * @return array<string, mixed>
   *   The reviewedBy property, or an empty array.
   */
  protected function schemaReviewedBy(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
    if (!$this->hasValue($node, $display, 'field_reviewed_by_name')) {
      return [];
    }
    $name = $this->plainText((string) $node->get('field_reviewed_by_name')->value);
    if ($name === '') {
      return [];
    }
    return ['reviewedBy' => ['@type' => 'Person', 'name' => $name]];
  }

  /**
   * Organization contact data from the first section_contact_panel, or NULL.
   *
   * Split by schema.org domain so the pieces attach where they are valid: a
   * `ContactPoint` (reachable channels only — telephone/email) nests under the
   * provider Organization, the postal `address` is an Organization property in
   * its own right (ContactPoint has no `address`), and structured opening hours
   * become `hoursAvailable` (an array of `OpeningHoursSpecification`). Hours
   * are read defensively from the office_hours field's stored columns through
   * OpeningHoursMapper, so the module keeps no dependency on the office_hours
   * contrib module: when the field is absent, empty, or not office_hours-shaped
   * the mapper returns [] and no hours are emitted — parity-safe, since a wrong
   * value is a worse signal than an absent one. The first panel carrying any
   * contact datum wins; every piece is present-and-non-empty gated.
   *
   * @return array{contactPoint: array<string, mixed>|null, address: array<string, mixed>|null, hoursAvailable: array<int, array<string, string>>|null}|null
   *   The contactPoint, address, and hoursAvailable pieces to attach to the
   *   provider Organization (hours fall back to the Service), or NULL when no
   *   panel carries contact data.
   */
  protected function organizationContactFromSections(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): ?array {
    if (!$this->hasValue($node, $display, 'field_sections')) {
      return NULL;
    }
    foreach ($node->get('field_sections')->referencedEntities() as $section) {
      if ($section->bundle() !== 'section_contact_panel') {
        continue;
      }
      $context->cacheability->addCacheableDependency($section);

      // ContactPoint carries the reachable channels only (Organization-valid
      // properties live on the Organization itself, below).
      $contact_point = ['@type' => 'ContactPoint'];
      $channels = [
        'field_section_phone' => 'telephone',
        'field_section_email' => 'email',
      ];
      foreach ($channels as $field => $property) {
        if ($section->hasField($field) && !$section->get($field)->isEmpty()) {
          $value = $this->plainText((string) $section->get($field)->value);
          if ($value !== '') {
            $contact_point[$property] = $value;
          }
        }
      }
      // Only a ContactPoint with at least one channel beyond @type is useful.
      $contact_point = count($contact_point) > 1 ? $contact_point : NULL;

      // The postal address is an Organization property, not a ContactPoint one.
      $address = NULL;
      if ($section->hasField('field_section_address') && !$section->get('field_section_address')->isEmpty()) {
        $street = $this->plainText((string) $section->get('field_section_address')->value);
        if ($street !== '') {
          $address = ['@type' => 'PostalAddress', 'streetAddress' => $street];
        }
      }

      // Structured opening hours → hoursAvailable. Read the raw office_hours
      // value columns and map them; the mapper drops anything it cannot
      // represent faithfully and returns [] for a non-office_hours field.
      $hours = NULL;
      if ($section->hasField('field_section_hours') && !$section->get('field_section_hours')->isEmpty()) {
        $specifications = OpeningHoursMapper::mapRows($section->get('field_section_hours')->getValue());
        if ($specifications !== []) {
          $hours = $specifications;
        }
      }

      if ($contact_point !== NULL || $address !== NULL || $hours !== NULL) {
        return [
          'contactPoint' => $contact_point,
          'address' => $address,
          'hoursAvailable' => $hours,
        ];
      }
    }
    return NULL;
  }

}
