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
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Integration test: a real office_hours field becomes Service hoursAvailable.
 *
 * The pure mapping is unit-tested (OpeningHoursMapperTest); this test exercises
 * the glue that test cannot reach — reading a genuine `office_hours` field's
 * stored columns off a section_contact_panel paragraph, through the
 * container-wired builder + ServiceNormalizer, and asserting both placement
 * branches: hours nest under the ContactPoint when a reachable channel exists,
 * and fall back to the Service when none does. office_hours is a test-only
 * (require-dev) dependency; the shipped module never depends on it.
 */
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class HoursAvailableEmissionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'file',
    'node',
    'datetime',
    'entity_reference_revisions',
    'paragraphs',
    'office_hours',
    'geo_starter_jsonld',
  ];

  /**
   * The real container-wired graph builder (with its tagged normalizers).
   */
  private JsonLdGraphBuilder $builder;

  /**
   * The full-mode view display for service nodes (renders field_sections).
   */
  private EntityViewDisplayInterface $display;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installConfig(['geo_starter_jsonld']);
    // A deterministic provider Organization name so the provider exists.
    $this->config('geo_starter_jsonld.settings')->set('organization_name', 'Demo City')->save();

    NodeType::create(['type' => 'service', 'name' => 'Service'])->save();
    ParagraphsType::create(['id' => 'section_contact_panel', 'label' => 'Contact panel'])->save();

    // The structured hours source: a genuine office_hours field.
    FieldStorageConfig::create([
      'field_name' => 'field_section_hours',
      'entity_type' => 'paragraph',
      'type' => 'office_hours',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_section_hours',
      'entity_type' => 'paragraph',
      'bundle' => 'section_contact_panel',
    ])->save();

    // A reachable channel; its presence decides which branch hosts the hours.
    $this->createStringField('paragraph', 'section_contact_panel', 'field_section_phone');
    $this->createParagraphRefField('node', 'service', 'field_sections');

    // Render field_sections so the node-level parity guard passes. In-memory
    // only (the guard reads getComponent()); saving would trigger dependency
    // calculation against formatters this minimal fixture does not configure.
    $this->display = \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'service', 'full')
      ->setComponent('field_sections', ['weight' => 0]);

    $this->builder = $this->container->get('geo_starter_jsonld.graph_builder');
  }

  /**
   * Hours nest under the ContactPoint when a reachable channel is present.
   */
  public function testHoursNestUnderContactPointWhenChannelExists(): void {
    $node = $this->serviceWithContactPanel([
      'field_section_phone' => '+1-555-0100',
      'field_section_hours' => [
        ['day' => 1, 'all_day' => FALSE, 'starthours' => 900, 'endhours' => 1700],
        ['day' => 2, 'all_day' => FALSE, 'starthours' => 900, 'endhours' => 1700],
      ],
    ]);
    $service = $this->firstOfType($this->buildGraph($node), 'Service');
    $this->assertNotNull($service);

    $hours = $service['provider']['contactPoint']['hoursAvailable'] ?? NULL;
    $this->assertIsArray($hours);
    $this->assertCount(2, $hours);
    $this->assertSame('OpeningHoursSpecification', $hours[0]['@type']);
    $this->assertSame('https://schema.org/Monday', $hours[0]['dayOfWeek']);
    $this->assertSame('09:00', $hours[0]['opens']);
    $this->assertSame('17:00', $hours[0]['closes']);
    $this->assertSame('https://schema.org/Tuesday', $hours[1]['dayOfWeek']);

    // Not also duplicated onto the Service when the ContactPoint carries it.
    $this->assertArrayNotHasKey('hoursAvailable', $service);
  }

  /**
   * Hours fall back to the Service when no reachable channel exists.
   */
  public function testHoursFallBackToServiceWithoutChannel(): void {
    $node = $this->serviceWithContactPanel([
      'field_section_hours' => [
        ['day' => 1, 'all_day' => FALSE, 'starthours' => 900, 'endhours' => 1700],
        ['day' => 2, 'all_day' => FALSE, 'starthours' => 900, 'endhours' => 1700],
      ],
    ]);
    $service = $this->firstOfType($this->buildGraph($node), 'Service');
    $this->assertNotNull($service);

    // No phone/email → no ContactPoint → hours live on the Service itself.
    $this->assertArrayNotHasKey('contactPoint', $service['provider'] ?? []);
    $hours = $service['hoursAvailable'] ?? NULL;
    $this->assertIsArray($hours);
    $this->assertCount(2, $hours);
    $this->assertSame('https://schema.org/Monday', $hours[0]['dayOfWeek']);
  }

  /**
   * Build a published service node carrying one section_contact_panel.
   *
   * @param array<string, mixed> $fields
   *   Field values for the paragraph (phone and/or office_hours rows).
   */
  private function serviceWithContactPanel(array $fields): NodeInterface {
    $panel = Paragraph::create(['type' => 'section_contact_panel'] + $fields);
    $panel->save();
    $node = Node::create([
      'type' => 'service',
      'title' => 'Emergency assistance',
      'status' => 1,
      'field_sections' => [['entity' => $panel]],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Build the JSON-LD @graph for a node, asserting something was emitted.
   *
   * @return array<int, array<string, mixed>>
   *   The decoded @graph.
   */
  private function buildGraph(NodeInterface $node): array {
    $result = $this->builder->build($node, $this->display);
    $this->assertNotNull($result, 'the published Service should emit JSON-LD');
    return json_decode($result['json'], TRUE)['@graph'];
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
   * Create a single-value string field on a bundle.
   */
  private function createStringField(string $entityType, string $bundle, string $name): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => 'string',
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Create an entity_reference_revisions field targeting paragraphs.
   */
  private function createParagraphRefField(string $entityType, string $bundle, string $name): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => 'entity_reference_revisions',
        'settings' => ['target_type' => 'paragraph'],
        'cardinality' => -1,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'settings' => ['handler' => 'default:paragraph'],
    ])->save();
  }

}
