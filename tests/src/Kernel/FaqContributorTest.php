<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\geo_starter_jsonld\Contributor\FaqContributor;
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
 * Tests the content gate of FaqContributor against real paragraph fixtures.
 *
 * The marquee GEO pattern: an invalid or thin FAQPage is worse than none, so a
 * FAQPage is emitted ONLY when at least two section_faq_item children have both
 * a question AND an answer, on an enabled bundle whose flag is on, with the
 * section host field rendered. These tests build that content model from
 * scratch (no recipe coupling) and exercise each arm of the gate.
 */
#[CoversClass(FaqContributor::class)]
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class FaqContributorTest extends KernelTestBase {

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
  private FaqContributor $contributor;

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
    ParagraphsType::create(['id' => 'section_faq', 'label' => 'FAQ'])->save();
    ParagraphsType::create(['id' => 'section_faq_item', 'label' => 'FAQ item'])->save();

    $this->createStringField('paragraph', 'section_faq_item', 'field_section_question');
    $this->createStringField('paragraph', 'section_faq_item', 'field_section_answer');
    $this->createParagraphRefField('paragraph', 'section_faq', 'field_section_items');
    $this->createParagraphRefField('node', 'service', 'field_sections');

    // Render field_sections in the full display so the parity guard passes.
    // The display is used in-memory only (applies() reads getComponent()); it
    // is deliberately not saved, which would trigger dependency calculation
    // against formatters this minimal fixture does not configure.
    $this->display = \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'service', 'full')
      ->setComponent('field_sections', ['weight' => 0]);

    $this->contributor = $this->container->get('geo_starter_jsonld.contributor.faq');
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

  /**
   * Build a published service node carrying one section_faq of Q/A pairs.
   *
   * @param array<int, array{0: string, 1: string}> $pairs
   *   Each pair is [question, answer]; '' marks a deliberately empty side.
   */
  private function serviceWithFaq(array $pairs): NodeInterface {
    $items = [];
    foreach ($pairs as [$q, $a]) {
      $item = Paragraph::create([
        'type' => 'section_faq_item',
        'field_section_question' => $q,
        'field_section_answer' => $a,
      ]);
      $item->save();
      $items[] = ['entity' => $item];
    }
    $faq = Paragraph::create(['type' => 'section_faq', 'field_section_items' => $items]);
    $faq->save();

    $node = Node::create([
      'type' => 'service',
      'title' => 'Emergency assistance',
      'status' => 1,
      'field_sections' => [['entity' => $faq]],
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
   * Two valid Q&A pairs emit a FAQPage with both questions.
   */
  public function testTwoValidPairsEmitFaqPage(): void {
    $node = $this->serviceWithFaq([['Q one', 'A one'], ['Q two', 'A two']]);
    $this->assertTrue($this->contributor->applies($node, $this->display));

    $objects = $this->contributor->contribute($node, $this->display, $this->context());
    $this->assertCount(1, $objects);
    $faqPage = $objects[0];
    $this->assertSame('FAQPage', $faqPage['@type']);
    $this->assertCount(2, $faqPage['mainEntity']);
    $this->assertSame('Q one', $faqPage['mainEntity'][0]['name']);
    $this->assertSame('A one', $faqPage['mainEntity'][0]['acceptedAnswer']['text']);
    $this->assertSame('Question', $faqPage['mainEntity'][0]['@type']);
  }

  /**
   * A single Q&A pair is below the minimum and emits nothing.
   */
  public function testSinglePairIsBelowMinimumAndEmitsNothing(): void {
    $node = $this->serviceWithFaq([['Only question', 'Only answer']]);
    $this->assertSame([], $this->contributor->contribute($node, $this->display, $this->context()));
  }

  /**
   * A pair with an empty answer is not counted toward the minimum.
   */
  public function testPairWithEmptyAnswerIsNotCounted(): void {
    // Two items, but one answer is empty → only one valid pair → no FAQPage.
    $node = $this->serviceWithFaq([['Q one', 'A one'], ['Q two', '']]);
    $this->assertSame([], $this->contributor->contribute($node, $this->display, $this->context()));
  }

  /**
   * The contributor does not apply when the FAQPage flag is off.
   */
  public function testFlagOffMeansDoesNotApply(): void {
    $this->config('geo_starter_jsonld.settings')->set('faqpage_on_service', FALSE)->save();
    $node = $this->serviceWithFaq([['Q one', 'A one'], ['Q two', 'A two']]);
    $this->assertFalse($this->contributor->applies($node, $this->display));
  }

  /**
   * The contributor does not apply when field_sections is not rendered.
   */
  public function testHiddenSectionFieldMeansDoesNotApply(): void {
    $node = $this->serviceWithFaq([['Q one', 'A one'], ['Q two', 'A two']]);
    // Parity guard: if field_sections is not rendered, nothing is emitted.
    $hidden = clone $this->display;
    $hidden->removeComponent('field_sections');
    $this->assertFalse($this->contributor->applies($node, $hidden));
  }

}
