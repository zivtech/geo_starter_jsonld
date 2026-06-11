<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\geo_starter_jsonld\Normalizer\EvidenceSourceNormalizer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests EvidenceSourceNormalizer url/sameAs guard for malformed URIs.
 *
 * The link widget validates URIs on form submit; migration, JSON:API, and
 * programmatic saves bypass that validation. A stored URI the Url factory
 * rejects must degrade gracefully (no url/sameAs emitted) rather than throwing
 * an uncaught InvalidArgumentException that renders the page as a 500.
 */
#[CoversClass(EvidenceSourceNormalizer::class)]
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class EvidenceSourceUrlGuardTest extends KernelTestBase {

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
  private EvidenceSourceNormalizer $normalizer;

  /**
   * In-memory full display with field_source_url and field_publisher rendered.
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

    NodeType::create(['type' => 'evidence_source', 'name' => 'Evidence Source'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_source_url',
      'entity_type' => 'node',
      'type' => 'link',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_source_url',
      'entity_type' => 'node',
      'bundle' => 'evidence_source',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_publisher',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_publisher',
      'entity_type' => 'node',
      'bundle' => 'evidence_source',
    ])->save();

    // Build the display in-memory only — saving would trigger dependency
    // calculation against formatters this minimal fixture does not configure.
    $this->display = \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'evidence_source', 'full')
      ->setComponent('field_source_url', ['weight' => 0])
      ->setComponent('field_publisher', ['weight' => 1]);

    $this->normalizer = $this->container->get('geo_starter_jsonld.normalizer.evidence_source');
  }

  /**
   * Fresh per-node build context with a fixed canonical URL.
   */
  private function context(): JsonLdContext {
    return new JsonLdContext('https://example.com/evidence/study-1', new CacheableMetadata());
  }

  /**
   * Valid external URL: url and sameAs are both emitted with the same value.
   */
  public function testValidExternalUrlEmitsUrlAndSameAs(): void {
    $node = Node::create([
      'type' => 'evidence_source',
      'title' => 'NEJM Study',
      'status' => 1,
      'field_source_url' => [['uri' => 'https://www.nejm.org/doi/full/10.1056/study', 'title' => '']],
      'field_publisher' => 'NEJM',
    ]);
    $node->save();

    $objects = $this->normalizer->normalize($node, $this->display, $this->context());

    $this->assertCount(1, $objects);
    $creative_work = $objects[0];
    $this->assertSame('https://www.nejm.org/doi/full/10.1056/study', $creative_work['url']);
    $this->assertSame($creative_work['url'], $creative_work['sameAs']);
    // The publisher is also emitted — proving the guard does not short-circuit
    // the rest of the normalizer for valid input.
    $this->assertSame('NEJM', $creative_work['publisher']['name'] ?? NULL);
  }

  /**
   * Malformed URI: no url/sameAs emitted, no exception; publisher still emits.
   *
   * Proves the guard absorbs the InvalidArgumentException thrown by
   * Url::fromUri() and continues normalizing the rest of the node's fields.
   */
  public function testMalformedUriOmitsUrlAndSameAsWithoutThrowing(): void {
    $node = Node::create([
      'type' => 'evidence_source',
      'title' => 'Bad URL Study',
      'status' => 1,
      'field_publisher' => 'Some Publisher',
    ]);
    $node->save();

    // Write the raw URI after save, bypassing constraint validation — this
    // replicates a migrated or programmatically created node with a bad URI.
    $node->get('field_source_url')->setValue([['uri' => 'not a uri', 'title' => '']]);

    // Must not throw; the guard must absorb the InvalidArgumentException.
    $objects = $this->normalizer->normalize($node, $this->display, $this->context());

    $this->assertCount(1, $objects);
    $creative_work = $objects[0];
    // Neither url nor sameAs should be present.
    $this->assertArrayNotHasKey('url', $creative_work);
    $this->assertArrayNotHasKey('sameAs', $creative_work);
    // The publisher field is still emitted — the guard is scoped only to the
    // url block and does not abort the whole normalizer.
    $this->assertArrayHasKey('publisher', $creative_work);
    $this->assertSame('Some Publisher', $creative_work['publisher']['name'] ?? NULL);
  }

}
