<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\geo_starter_jsonld\Normalizer\ServiceNormalizer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ServiceNormalizer potentialAction URL cacheability and malformed guard.
 *
 * Exercises two behaviors that unit tests cannot reach without a real router:
 * (1) toString(TRUE) for an internal URI generates a GeneratedUrl whose
 * cacheability (url.site context, route cache tags) lands in the build context;
 * (2) a stored URI that the Url factory rejects degrades cleanly to "no
 * potentialAction emitted" rather than throwing a 500.
 */
#[CoversClass(ServiceNormalizer::class)]
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class ServiceNormalizerActionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'link',
    'node',
    'geo_starter_jsonld',
  ];

  /**
   * The normalizer under test.
   */
  private ServiceNormalizer $normalizer;

  /**
   * In-memory full display with field_next_action rendered.
   */
  private EntityViewDisplayInterface $display;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['geo_starter_jsonld']);

    NodeType::create(['type' => 'service', 'name' => 'Service'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_next_action',
      'entity_type' => 'node',
      'type' => 'link',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_next_action',
      'entity_type' => 'node',
      'bundle' => 'service',
    ])->save();

    // Build the display in-memory only — saving would trigger dependency
    // calculation against formatters this minimal fixture does not configure.
    $this->display = \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'service', 'full')
      ->setComponent('field_next_action', ['weight' => 0]);

    $this->normalizer = $this->container->get('geo_starter_jsonld.normalizer.service');
  }

  /**
   * Fresh per-node build context with a fixed canonical URL.
   */
  private function context(): JsonLdContext {
    return new JsonLdContext('http://localhost/service/test', new CacheableMetadata());
  }

  /**
   * Internal URI: potentialAction.target is absolute; cacheability is tracked.
   *
   * The canonical proof that toString(TRUE) is used: a fresh context built
   * WITHOUT the normalizer call has no cache contexts, while the context after
   * normalize() carries the url.site cache context contributed by GeneratedUrl
   * (Drupal adds it for every path-based internal URL).
   */
  public function testInternalUriEmitsAbsoluteTargetAndTracksCacheability(): void {
    $node = Node::create([
      'type' => 'service',
      'title' => 'Apply for benefits',
      'status' => 1,
      'field_next_action' => [['uri' => 'internal:/apply', 'title' => 'Apply now']],
    ]);
    $node->save();

    $context = $this->context();
    $objects = $this->normalizer->normalize($node, $this->display, $context);

    $this->assertCount(1, $objects);
    $service = $objects[0];

    // The potentialAction must be present and target must be an absolute URL.
    $this->assertArrayHasKey('potentialAction', $service);
    $action = $service['potentialAction'];
    $this->assertSame('Action', $action['@type']);
    $this->assertStringStartsWith('http', $action['target']);
    $this->assertSame('Apply now', $action['name']);

    // toString(TRUE) adds url.site to the cache contexts of the GeneratedUrl;
    // addCacheableDependency() must have merged it into the build context.
    $this->assertContains('url.site', $context->cacheability->getCacheContexts());
  }

  /**
   * Malformed stored URI: potentialAction is omitted; no exception is thrown.
   *
   * Kernel tests write raw field values, bypassing widget validation, so the
   * URI is persisted as-is and exercises the guard path in the normalizer.
   */
  public function testMalformedUriSkipsPotentialActionWithoutThrowing(): void {
    $node = Node::create([
      'type' => 'service',
      'title' => 'Benefits info',
      'status' => 1,
    ]);
    $node->save();

    // Write the raw URI value after save, bypassing constraint validation.
    $node->get('field_next_action')->setValue([['uri' => 'not a uri', 'title' => '']]);

    $context = $this->context();
    // Must not throw; the guard must absorb the InvalidArgumentException.
    $objects = $this->normalizer->normalize($node, $this->display, $context);

    $this->assertCount(1, $objects);
    $service = $objects[0];
    // The potentialAction block is skipped entirely.
    $this->assertArrayNotHasKey('potentialAction', $service);
    // The primary entity is still emitted with its name.
    $this->assertSame('Benefits info', $service['name']);
  }

}
