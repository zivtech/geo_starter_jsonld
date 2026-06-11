<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\geo_starter_jsonld\Contributor\StepListContributor;
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
 * Tests StepListContributor emits HowTo with section heading, not step name.
 *
 * The marquee GEO pattern: an invalid or thin HowTo is worse than none, so a
 * HowTo is emitted ONLY when at least two section_step_item children have both
 * a non-empty step name AND step text. The HowTo.name must come from the
 * section heading, not from the last processed step item.
 */
#[CoversClass(StepListContributor::class)]
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class StepListContributorTest extends KernelTestBase {

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
  private StepListContributor $contributor;

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
    ParagraphsType::create(['id' => 'section_step_list', 'label' => 'Step list'])->save();
    ParagraphsType::create(['id' => 'section_step_item', 'label' => 'Step item'])->save();

    $this->createStringField('paragraph', 'section_step_list', 'field_section_heading');
    $this->createStringField('paragraph', 'section_step_item', 'field_section_step_name');
    $this->createStringField('paragraph', 'section_step_item', 'field_section_step_text');
    $this->createParagraphRefField('paragraph', 'section_step_list', 'field_section_steps');
    $this->createParagraphRefField('node', 'service', 'field_sections');

    // Render field_sections in the full display so the parity guard passes.
    // The display is used in-memory only (applies() reads getComponent()); it
    // is deliberately not saved, which would trigger dependency calculation
    // against formatters this minimal fixture does not configure.
    $this->display = \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'service', 'full')
      ->setComponent('field_sections', ['weight' => 0]);

    $this->contributor = $this->container->get('geo_starter_jsonld.contributor.step_list');
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
   * Build a published service node carrying step list sections.
   *
   * @param array<int, array{heading: string, steps: array<int, array{0: string, 1: string}>}> $sections
   *   Each element has a 'heading' string and a 'steps' array of [name, text]
   *   pairs; '' marks a deliberately empty value.
   */
  private function serviceWithStepLists(array $sections): NodeInterface {
    $sectionRefs = [];
    foreach ($sections as $sectionData) {
      $stepRefs = [];
      foreach ($sectionData['steps'] as [$stepName, $stepText]) {
        $item = Paragraph::create([
          'type' => 'section_step_item',
          'field_section_step_name' => $stepName,
          'field_section_step_text' => $stepText,
        ]);
        $item->save();
        $stepRefs[] = ['entity' => $item];
      }
      $stepList = Paragraph::create([
        'type' => 'section_step_list',
        'field_section_heading' => $sectionData['heading'],
        'field_section_steps' => $stepRefs,
      ]);
      $stepList->save();
      $sectionRefs[] = ['entity' => $stepList];
    }

    $node = Node::create([
      'type' => 'service',
      'title' => 'Benefits guide',
      'status' => 1,
      'field_sections' => $sectionRefs,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Fresh per-node build context with a fixed canonical URL.
   */
  private function context(): JsonLdContext {
    return new JsonLdContext('http://localhost/service/benefits', new CacheableMetadata());
  }

  /**
   * HowTo name is the section heading, not the last step's name (regression).
   *
   * Before the fix, $name was clobbered by each step, so HowTo.name ended up
   * as "Submit the form" instead of "How to apply".
   */
  public function testHowToNameIsHeadingNotLastStepName(): void {
    $node = $this->serviceWithStepLists([
      [
        'heading' => 'How to apply',
        'steps' => [
          ['Gather documents', 'Collect your ID and proof of address.'],
          ['Submit the form', 'Fill out form B-12 and mail it in.'],
        ],
      ],
    ]);
    $this->assertTrue($this->contributor->applies($node, $this->display));

    $objects = $this->contributor->contribute($node, $this->display, $this->context());
    $this->assertCount(1, $objects);
    $howto = $objects[0];
    $this->assertSame('HowTo', $howto['@type']);
    $this->assertArrayHasKey('name', $howto);
    $this->assertSame('How to apply', $howto['name']);
    $this->assertCount(2, $howto['step']);
    $this->assertSame('Gather documents', $howto['step'][0]['name']);
    $this->assertSame('Submit the form', $howto['step'][1]['name']);
  }

  /**
   * HowTo name is the second section heading when the first has no heading.
   *
   * Before the fix, the guard "$name === ''" saw the previous step's name, so
   * the second heading could never be captured.
   */
  public function testTwoStepListsSecondHeadingCapturedWhenFirstIsEmpty(): void {
    $node = $this->serviceWithStepLists([
      [
        'heading' => '',
        'steps' => [
          ['Check eligibility', 'Review the requirements on our website.'],
          ['Contact us', 'Call the helpline to confirm your eligibility.'],
        ],
      ],
      [
        'heading' => 'Renewal steps',
        'steps' => [
          ['Download renewal form', 'Get the PDF from our downloads page.'],
          ['Return completed form', 'Mail the form with supporting documents.'],
        ],
      ],
    ]);
    $objects = $this->contributor->contribute($node, $this->display, $this->context());
    $this->assertCount(1, $objects);
    $howto = $objects[0];
    $this->assertArrayHasKey('name', $howto);
    $this->assertSame('Renewal steps', $howto['name']);
  }

  /**
   * HowTo without any heading emits no name key.
   *
   * Nameless HowTo is parity-safe documented behavior; steps still emit.
   */
  public function testNoHeadingAnywhereMeansNoNameKey(): void {
    $node = $this->serviceWithStepLists([
      [
        'heading' => '',
        'steps' => [
          ['First step', 'Do the first thing.'],
          ['Second step', 'Do the second thing.'],
        ],
      ],
    ]);
    $objects = $this->contributor->contribute($node, $this->display, $this->context());
    $this->assertCount(1, $objects);
    $howto = $objects[0];
    $this->assertArrayNotHasKey('name', $howto);
    $this->assertCount(2, $howto['step']);
  }

  /**
   * A single valid step is below the minimum and emits nothing.
   *
   * The MINIMUM_STEPS gate must remain intact after the variable rename.
   */
  public function testSingleValidStepIsBelowMinimumAndEmitsNothing(): void {
    $node = $this->serviceWithStepLists([
      [
        'heading' => 'Only one step',
        'steps' => [
          ['Only step', 'This is the only step.'],
        ],
      ],
    ]);
    $objects = $this->contributor->contribute($node, $this->display, $this->context());
    $this->assertSame([], $objects);
  }

}
