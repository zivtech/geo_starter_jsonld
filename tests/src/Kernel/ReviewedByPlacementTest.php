<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Kernel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\geo_starter_jsonld\JsonLdGraphBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guards the schema.org domain invariant for reviewedBy across all normalizers.
 *
 * `reviewedBy` is WebPage-domain-only in schema.org (not CreativeWork), so it
 * belongs on the WebPage for every bundle — never on the primary entity
 * (Service, Article, Question). This already regressed once
 * (Question.reviewedBy on Answer nodes, caught only by validating all node
 * types). The test drives the real container-wired builder + tagged
 * normalizers for each emitting bundle and asserts, generically, that the
 * WebPage carries reviewedBy and no other graph node does — so a future
 * normalizer that merges it onto its entity fails here. Only the
 * field_reviewed_by_name field is created; every other field the normalizers
 * read is hasField-guarded.
 */
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class ReviewedByPlacementTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'geo_starter_jsonld',
  ];

  /**
   * Node bundles whose normalizer emits reviewedBy, mapped to the primary type.
   */
  private const BUNDLES = [
    'service' => 'Service',
    'article' => 'Article',
    'answer' => 'Question',
  ];

  /**
   * The real container-wired graph builder (with its tagged normalizers).
   */
  private JsonLdGraphBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['geo_starter_jsonld']);
    $this->builder = $this->container->get('geo_starter_jsonld.graph_builder');

    FieldStorageConfig::create([
      'field_name' => 'field_reviewed_by_name',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    foreach (array_keys(self::BUNDLES) as $bundle) {
      NodeType::create(['type' => $bundle, 'name' => ucfirst($bundle)])->save();
      FieldConfig::create([
        'field_name' => 'field_reviewed_by_name',
        'entity_type' => 'node',
        'bundle' => $bundle,
      ])->save();
    }
  }

  /**
   * The full-mode display for a bundle with the reviewer field rendered.
   *
   * Built in-memory only (the parity guard reads getComponent()); saving it
   * would trigger dependency calculation against formatters it does not set.
   */
  private function displayFor(string $bundle): EntityViewDisplayInterface {
    return \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', $bundle, 'full')
      ->setComponent('field_reviewed_by_name', ['weight' => 0]);
  }

  /**
   * Return the first graph object of the given @type, or NULL.
   */
  private function firstOfType(array $graph, string $type): ?array {
    foreach ($graph as $object) {
      if (($object['@type'] ?? NULL) === $type) {
        return $object;
      }
    }
    return NULL;
  }

  /**
   * Asserts reviewedBy lands on the WebPage, never on the primary entity.
   */
  public function testReviewedByOnlyEverAppearsOnTheWebPage(): void {
    foreach (self::BUNDLES as $bundle => $primaryType) {
      $node = Node::create([
        'type' => $bundle,
        'title' => ucfirst($bundle) . ' under review',
        'status' => TRUE,
        'field_reviewed_by_name' => 'Editorial Review Board',
      ]);
      $node->save();

      $result = $this->builder->build($node, $this->displayFor($bundle));
      $this->assertNotNull($result, "$bundle should emit JSON-LD");
      $graph = json_decode($result['json'], TRUE)['@graph'];

      // The primary entity of the expected type is present (sanity).
      $this->assertNotNull(
        $this->firstOfType($graph, $primaryType),
        "$bundle: expected a $primaryType primary entity",
      );

      // The WebPage carries reviewedBy — proving it was emitted AND routed, so
      // a normalizer that simply stopped emitting it cannot pass vacuously.
      $webPage = $this->firstOfType($graph, 'WebPage');
      $this->assertNotNull($webPage, "$bundle: WebPage must be present");
      $this->assertArrayHasKey('reviewedBy', $webPage, "$bundle: reviewedBy must be on the WebPage");
      $this->assertSame('Editorial Review Board', $webPage['reviewedBy']['name'] ?? NULL);

      // No other node in the graph may carry reviewedBy.
      foreach ($graph as $object) {
        if (($object['@type'] ?? NULL) !== 'WebPage') {
          $this->assertArrayNotHasKey(
            'reviewedBy',
            $object,
            "$bundle: reviewedBy must not appear on {$object['@type']}",
          );
        }
      }

      // No node may carry a `review` property. Provenance is reviewedBy +
      // dateModified only; a bare `Review` (no reviewRating) is a valid schema
      // but an INVALID Google Review-snippet rich result, flagged on every
      // governed page by the Rich Results Test (WS-D Phase 1, 2026-06-07).
      // This guards the regression at the graph level for every node type.
      foreach ($graph as $object) {
        $this->assertArrayNotHasKey(
          'review',
          $object,
          "$bundle: no `review` (rating-less Review) may be emitted on {$object['@type']}",
        );
      }
    }
  }

}
