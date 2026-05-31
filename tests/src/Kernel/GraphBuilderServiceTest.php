<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Kernel;

use Drupal\geo_starter_jsonld\JsonLdGraphBuilder;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Smoke test: the module boots and its tagged-service graph wires together.
 *
 * Kept deliberately minimal — it proves the kernel harness (DB, autoloading,
 * container compilation, tagged_iterator collection) works for this module
 * before the heavier fixture-based tests build real entities on top of it.
 */
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class GraphBuilderServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'geo_starter_jsonld',
  ];

  /**
   * The graph builder service is defined and collects its tagged plugins.
   */
  public function testGraphBuilderServiceIsWired(): void {
    $builder = $this->container->get('geo_starter_jsonld.graph_builder');
    $this->assertInstanceOf(JsonLdGraphBuilder::class, $builder);
  }

}
