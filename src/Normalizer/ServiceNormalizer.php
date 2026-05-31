<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld\Normalizer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\geo_starter_jsonld\JsonLdFieldTrait;
use Drupal\node\NodeInterface;

/**
 * Normalizes a Service node to a schema.org Service object.
 *
 * Intentionally thin (SCHEMA_MAP.md / jsonld plan §2.1): name, description,
 * potentialAction, about, audience, dateModified, citation, provider. The
 * visibly-rendered field_problem_solved / field_eligibility have no faithful
 * schema.org property on Service and are deliberately omitted — a wrong
 * property is a worse GEO signal than an absent one, and emitting less than the
 * visible HTML is parity-safe.
 */
final class ServiceNormalizer implements NodeNormalizerInterface {

  use JsonLdFieldTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node): bool {
    return $node->bundle() === 'service';
  }

  /**
   * {@inheritdoc}
   */
  public function normalize(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
    $settings = $this->configFactory->get('geo_starter_jsonld.settings');

    $service = [
      '@type' => $settings->get('service_type') ?: 'Service',
      '@id' => $context->canonicalUrl . '#service',
      'name' => $node->label(),
    ];

    if ($this->hasValue($node, $display, 'field_summary')) {
      $description = $this->plainText((string) $node->get('field_summary')->value);
      if ($description !== '') {
        $service['description'] = $description;
      }
    }

    if ($this->hasValue($node, $display, 'field_next_action')) {
      $link = $node->get('field_next_action')->first();
      $target = $link->getUrl()->setAbsolute()->toString();
      $title = trim((string) $link->title);
      $action = ['@type' => 'Action', 'target' => $target];
      if ($title !== '') {
        $action['name'] = $title;
      }
      $service['potentialAction'] = $action;
    }

    $about = $this->schemaAbout($node, $display, 'field_topic', $context);
    if ($about !== []) {
      $service['about'] = $about;
    }

    $audience = $this->schemaAudience($node, $display, 'field_audience', $context);
    if ($audience !== []) {
      $service['audience'] = $audience;
    }

    if ($this->hasValue($node, $display, 'field_reviewed_date')) {
      $service['dateModified'] = $this->isoDate((string) $node->get('field_reviewed_date')->value);
    }

    $service += $this->schemaReviewedBy($node, $display, $context);

    $citations = $this->resolveCitations($node, $display, 'field_evidence_sources', $context);
    if ($citations !== []) {
      $service['citation'] = $citations;
    }

    $organization = $settings->get('organization_name')
      ?: $this->configFactory->get('system.site')->get('name');
    if (is_string($organization) && trim($organization) !== '') {
      $service['provider'] = ['@type' => 'Organization', 'name' => trim($organization)];
    }

    // ContactPoint nests under the Service (never standalone); service-only.
    $contact_point = $this->contactPointFromSections($node, $display, $context);
    if ($contact_point !== NULL) {
      $service['contactPoint'] = $contact_point;
    }

    return [$service];
  }

}
