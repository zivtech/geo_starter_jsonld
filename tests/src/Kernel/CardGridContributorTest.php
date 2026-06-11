<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\geo_starter_jsonld\Contributor\CardGridContributor;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests CardGridContributor's published gating and cache-tag bubbling.
 *
 * Contract under test, recorded from the contributor source (all references
 * are CardGridContributor.php at the lines current when this test was
 * written): the bundle filter accepts only `section_card_grid` (line 42); the
 * card reference field is `field_section_cards` (lines 46, 52); each card's
 * cache dependency is added BEFORE the published check (lines 53-55), so an
 * unpublished target still invalidates the page when it is later published;
 * the drop condition is `!$card instanceof NodeInterface ||
 * !$card->isPublished()` (line 55); positions are 1-based among PUBLISHED
 * cards only (lines 58-61); a grid with zero emitted items contributes
 * nothing (lines 67-69); the list `@id` is `{canonicalUrl}#cards{N}`
 * (line 72). There is no minimum-items gate beyond "at least one".
 */
#[CoversClass(CardGridContributor::class)]
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class CardGridContributorTest extends KernelTestBase {

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
    'entity_reference_revisions',
    'paragraphs',
    'geo_starter_jsonld',
  ];

  /**
   * The contributor under test.
   */
  private CardGridContributor $contributor;

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

    NodeType::create(['type' => 'service', 'name' => 'Service'])->save();
    ParagraphsType::create(['id' => 'section_card_grid', 'label' => 'Card grid'])->save();
    // A decoy bundle for the bundle-filter case.
    ParagraphsType::create(['id' => 'section_plain', 'label' => 'Plain section'])->save();

    $this->createParagraphRefField('node', 'service', 'field_sections');
    $this->createNodeRefField('paragraph', 'section_card_grid', 'field_section_cards');

    // Render field_sections in the full display so the parity guard passes.
    // In-memory only — saving a minimal display triggers dependency
    // calculation against formatters this fixture does not configure (same
    // trap documented in FaqContributorTest).
    $this->display = \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'service', 'full')
      ->setComponent('field_sections', ['weight' => 0]);

    $this->contributor = $this->container->get('geo_starter_jsonld.contributor.card_grid');
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

  /**
   * Create an entity_reference field targeting nodes.
   */
  private function createNodeRefField(string $entityType, string $bundle, string $name): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'node'],
        'cardinality' => -1,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'settings' => ['handler' => 'default:node'],
    ])->save();
  }

  /**
   * Build a card target node (named to avoid core trait helper collisions).
   */
  private function cardNode(string $title, bool $published): NodeInterface {
    $node = Node::create([
      'type' => 'service',
      'title' => $title,
      'status' => $published ? 1 : 0,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Build a section_card_grid paragraph referencing the given card nodes.
   */
  private function cardGrid(array $cards): Paragraph {
    $grid = Paragraph::create([
      'type' => 'section_card_grid',
      'field_section_cards' => array_map(
        static fn (NodeInterface $card): array => ['target_id' => $card->id()],
        $cards,
      ),
    ]);
    $grid->save();
    return $grid;
  }

  /**
   * Build a published service node carrying the given section paragraphs.
   */
  private function serviceWithSections(array $sections): NodeInterface {
    $node = Node::create([
      'type' => 'service',
      'title' => 'Emergency assistance',
      'status' => 1,
      'field_sections' => array_map(
        static fn (Paragraph $section): array => ['entity' => $section],
        $sections,
      ),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Fresh per-node build context with a fixed canonical URL.
   */
  private function context(): JsonLdContext {
    return new JsonLdContext('http://localhost/service/emergency', new CacheableMetadata());
  }

  /**
   * Only published referenced nodes become ListItems, positions 1-based.
   */
  public function testPublishedOnlyEmission(): void {
    $published = $this->cardNode('Card one', TRUE);
    $unpublished = $this->cardNode('Card two', FALSE);
    $node = $this->serviceWithSections([$this->cardGrid([$published, $unpublished])]);
    $this->assertTrue($this->contributor->applies($node, $this->display));

    $objects = $this->contributor->contribute($node, $this->display, $this->context());
    $this->assertCount(1, $objects);
    $list = $objects[0];
    $this->assertSame('ItemList', $list['@type']);
    $this->assertSame('http://localhost/service/emergency#cards1', $list['@id']);
    $this->assertCount(1, $list['itemListElement'], 'The unpublished card was dropped from the emitted list.');
    $item = $list['itemListElement'][0];
    $this->assertSame('ListItem', $item['@type']);
    $this->assertSame(1, $item['position']);
    $this->assertSame('Card one', $item['name']);
    $this->assertStringEndsWith('/node/' . $published->id(), $item['url']);
  }

  /**
   * A dropped unpublished target still bubbles its cache tag.
   */
  public function testUnpublishedTargetCacheTagStillBubbles(): void {
    $published = $this->cardNode('Card one', TRUE);
    $unpublished = $this->cardNode('Card two', FALSE);
    $node = $this->serviceWithSections([$this->cardGrid([$published, $unpublished])]);

    $context = $this->context();
    $this->contributor->contribute($node, $this->display, $context);

    $tags = $context->cacheability->getCacheTags();
    $this->assertContains('node:' . $unpublished->id(), $tags, 'Publishing the dropped card later invalidates this page.');
    $this->assertContains('node:' . $published->id(), $tags);
  }

  /**
   * A grid with zero published cards contributes nothing.
   */
  public function testGridWithNoPublishedCardsEmitsNothing(): void {
    $unpublished = $this->cardNode('Card two', FALSE);
    $node = $this->serviceWithSections([$this->cardGrid([$unpublished])]);

    $this->assertSame([], $this->contributor->contribute($node, $this->display, $this->context()));
  }

  /**
   * Sections of other paragraph bundles are ignored.
   */
  public function testNonCardGridSectionIsIgnored(): void {
    $plain = Paragraph::create(['type' => 'section_plain']);
    $plain->save();
    $node = $this->serviceWithSections([$plain]);

    $this->assertSame([], $this->contributor->contribute($node, $this->display, $this->context()));
  }

}
