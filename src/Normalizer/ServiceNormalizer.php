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
 * Intentionally thin (SCHEMA_MAP.md / jsonld plan §2.1). The Service carries the
 * properties schema.org puts in its domain: name, description, potentialAction,
 * audience, and a provider Organization (which in turn hosts contactPoint and
 * address). Page-level CreativeWork metadata that Service is not in the domain
 * for — about, citation, dateModified, reviewedBy/review — is routed to the
 * WebPage via the build context (the WebPage links back through mainEntity).
 * The visibly-rendered field_problem_solved / field_eligibility have no faithful
 * schema.org property on Service and are deliberately omitted — a wrong property
 * is a worse GEO signal than an absent one, and emitting less than the visible
 * HTML is parity-safe.
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

    // Page-level CreativeWork metadata: a Service is not a CreativeWork, so
    // about/citation/dateModified are domain-valid on the WebPage rather than
    // here, and reviewedBy is WebPage-domain-only for every type. Route them to
    // the page spine — the WebPage links back via mainEntity, so the provenance
    // stays connected. audience IS valid on Service and stays.
    $context->addWebPageProperty('about', $this->schemaAbout($node, $display, 'field_topic', $context));

    $audience = $this->schemaAudience($node, $display, 'field_audience', $context);
    if ($audience !== []) {
      $service['audience'] = $audience;
    }

    if ($this->hasValue($node, $display, 'field_reviewed_date')) {
      $context->addWebPageProperty('dateModified', $this->isoDate((string) $node->get('field_reviewed_date')->value));
    }

    // reviewedBy and its paired review move together (never split across nodes).
    foreach ($this->schemaReviewedBy($node, $display, $context) as $property => $value) {
      $context->addWebPageProperty($property, $value);
    }

    $context->addWebPageProperty('citation', $this->resolveCitations($node, $display, 'field_evidence_sources', $context));

    // The provider Organization hosts the contact data: schema.org places
    // contactPoint and address on an Organization, not on a Service or a
    // ContactPoint. With no organization name there is nowhere domain-valid to
    // hang contact data, so it is omitted rather than placed on an unnamed Org.
    $organization = $settings->get('organization_name')
      ?: $this->configFactory->get('system.site')->get('name');
    if (is_string($organization) && trim($organization) !== '') {
      $provider = ['@type' => 'Organization', 'name' => trim($organization)];
      $contact = $this->organizationContactFromSections($node, $display, $context);
      if ($contact !== NULL) {
        if ($contact['contactPoint'] !== NULL) {
          $provider['contactPoint'] = $contact['contactPoint'];
        }
        if ($contact['address'] !== NULL) {
          $provider['address'] = $contact['address'];
        }
      }
      $service['provider'] = $provider;
    }

    return [$service];
  }

}
