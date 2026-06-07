<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld_markup\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guards the markup submodule's hook_theme() template registration.
 *
 * PRIMARY JOB (WS-B plan §10.H N1): if a hook_theme() entry is missing or its
 * key is mistyped, the bundle silently falls through to core's classless
 * paragraph template and the defining semantic element below is absent — the
 * assertion fails RED. Rendering goes through the real entity view builder +
 * renderRoot() so the genuine suggestion → registered-template selection path
 * is exercised (a direct template render would bypass the registry and give
 * false confidence). The semantic-element checks are the mechanism; the
 * registration guard is the purpose.
 *
 * Fixtures are built from scratch (no recipe coupling), mirroring the parent
 * module's kernel-test idiom. Fields use the plain `string` type for fixture
 * simplicity — these assertions are structural; formatter fidelity is covered
 * by the recipe's fresh-install acceptance run.
 */
#[CoversFunction('geo_starter_jsonld_markup_theme')]
#[Group('geo_starter_jsonld_markup')]
#[RunTestsInSeparateProcesses]
final class SectionMarkupTest extends KernelTestBase {

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
    'options',
    'link',
    'entity_reference_revisions',
    'paragraphs',
    'geo_starter_jsonld',
    'geo_starter_jsonld_markup',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installConfig(['geo_starter_jsonld']);

    $bundles = [
      'geo_starter_section',
      'section_faq',
      'section_faq_item',
      'section_step_list',
      'section_step_item',
      'section_card_grid',
      'section_cta',
      'section_alert',
      'section_contact_panel',
      'section_media_text',
    ];
    foreach ($bundles as $bundle) {
      ParagraphsType::create(['id' => $bundle, 'label' => $bundle])->save();
    }

    // Shared heading + per-bundle structural fields (minimal set per the
    // defining assertion of each template).
    foreach ($bundles as $bundle) {
      $this->createStringField($bundle, 'field_section_heading');
    }
    $this->createStringField('geo_starter_section', 'field_section_kicker');
    $this->createStringField('geo_starter_section', 'field_section_body');
    $this->createStringField('section_faq_item', 'field_section_question');
    $this->createStringField('section_faq_item', 'field_section_answer');
    $this->createParagraphRefField('section_faq', 'field_section_items');
    $this->createStringField('section_step_item', 'field_section_step_name');
    $this->createStringField('section_step_item', 'field_section_step_text');
    $this->createParagraphRefField('section_step_list', 'field_section_steps');
    $this->createStringField('section_cta', 'field_section_body');
    $this->createLinkField('section_cta', 'field_section_link');
    $this->createListField('section_alert', 'field_section_alert_level', [
      'info' => 'Info',
      'success' => 'Success',
      'warning' => 'Warning',
      'danger' => 'Danger',
    ]);
    $this->createStringField('section_alert', 'field_section_body');
    $this->createStringField('section_contact_panel', 'field_section_contact_name');
    $this->createStringField('section_media_text', 'field_section_body');
    $this->createListField('section_media_text', 'field_section_media_position', [
      'left' => 'Left',
      'right' => 'Right',
    ]);

    // Programmatic ParagraphsType creation generates NO view-display config —
    // without saved displays no field reaches content.* and every template
    // renders empty (the live site gets its displays from the recipe). Mirror
    // the recipe's semantics: all printed fields label-hidden; the two
    // modifier-source list fields (alert level, media position) stay OUT of
    // the display — the templates read their raw .value, never print them.
    $displayFields = [
      'geo_starter_section' => ['field_section_kicker', 'field_section_heading', 'field_section_body'],
      'section_faq' => ['field_section_heading', 'field_section_items'],
      'section_faq_item' => ['field_section_question', 'field_section_answer'],
      'section_step_list' => ['field_section_heading', 'field_section_steps'],
      'section_step_item' => ['field_section_step_name', 'field_section_step_text'],
      'section_card_grid' => ['field_section_heading'],
      'section_cta' => ['field_section_heading', 'field_section_body', 'field_section_link'],
      'section_alert' => ['field_section_heading', 'field_section_body'],
      'section_contact_panel' => ['field_section_heading', 'field_section_contact_name'],
      'section_media_text' => ['field_section_heading', 'field_section_body'],
    ];
    $repository = \Drupal::service('entity_display.repository');
    foreach ($displayFields as $bundle => $fields) {
      $display = $repository->getViewDisplay('paragraph', $bundle);
      foreach (array_values($fields) as $weight => $name) {
        $display->setComponent($name, ['label' => 'hidden', 'weight' => $weight]);
      }
      $display->save();
    }
  }

  /**
   * Renders each bundle through the view builder; asserts its template won.
   */
  public function testAllBundleTemplatesRender(): void {
    // Bundle => [fixture values, [required substrings]].
    $faqItem = Paragraph::create([
      'type' => 'section_faq_item',
      'field_section_question' => 'Who can apply?',
      'field_section_answer' => 'Residents with an urgent need.',
    ]);
    $faqItem->save();
    $stepItem = Paragraph::create([
      'type' => 'section_step_item',
      'field_section_step_name' => 'Gather documents',
      'field_section_step_text' => 'Bring an ID.',
    ]);
    $stepItem->save();

    $cases = [
      'geo_starter_section' => [
        [
          'field_section_kicker' => 'Good to know',
          'field_section_heading' => 'Generic heading',
          'field_section_body' => 'Body text.',
        ],
        ['geo-section--generic', '<h2 class="geo-section__title"', 'geo-section__kicker'],
      ],
      'section_faq' => [
        [
          'field_section_heading' => 'Common questions',
          'field_section_items' => [$faqItem],
        ],
        ['<dl class="geo-faq"', 'geo-faq__q', 'geo-faq__a'],
      ],
      'section_step_list' => [
        [
          'field_section_heading' => 'How to apply',
          'field_section_steps' => [$stepItem],
        ],
        ['<ol class="geo-steps"', 'geo-steps__item', 'geo-steps__name'],
      ],
      'section_card_grid' => [
        ['field_section_heading' => 'Related'],
        ['geo-section--cards', '<div class="geo-cards"'],
      ],
      'section_cta' => [
        [
          'field_section_heading' => 'Apply now',
          'field_section_body' => 'Start here.',
          'field_section_link' => ['uri' => 'https://example.org/apply', 'title' => 'Apply'],
        ],
        ['geo-section--cta', 'geo-cta__action'],
      ],
      'section_alert' => [
        [
          'field_section_alert_level' => 'warning',
          'field_section_heading' => 'Deadline approaching',
          'field_section_body' => 'Closes March 31.',
        ],
        ['role="note"', 'geo-alert--warning', 'geo-alert__title'],
      ],
      'section_contact_panel' => [
        [
          'field_section_heading' => 'Contact us',
          'field_section_contact_name' => 'Benefits Office',
        ],
        ['<address class="geo-contact"', 'geo-contact__name'],
      ],
      'section_media_text' => [
        [
          'field_section_heading' => 'How it works',
          'field_section_body' => 'Explainer.',
          'field_section_media_position' => 'right',
        ],
        ['geo-mediatext--media-right', 'geo-mediatext__grid'],
      ],
    ];

    foreach ($cases as $bundle => [$values, $needles]) {
      $paragraph = Paragraph::create(['type' => $bundle] + $values);
      $paragraph->save();
      $html = $this->renderParagraph($paragraph);
      foreach ($needles as $needle) {
        $this->assertStringContainsString(
          $needle,
          $html,
          sprintf('Bundle %s: "%s" missing — hook_theme() registration or template broken.', $bundle, $needle),
        );
      }
    }

    // Child bundles rendered standalone (they also have registrations).
    // Assert the full template body, not just the wrapper class — a template
    // that renders its root div but errors before the dt/dd would otherwise
    // pass green (theme-critic checkpoint #1, MAJOR-2).
    $faqItemHtml = $this->renderParagraph($faqItem);
    $this->assertStringContainsString('geo-faq__item', $faqItemHtml);
    $this->assertStringContainsString('<dt class="geo-faq__q"', $faqItemHtml);
    $this->assertStringContainsString('<dd class="geo-faq__a"', $faqItemHtml);
    $stepItemHtml = $this->renderParagraph($stepItem);
    $this->assertStringContainsString('geo-steps__item', $stepItemHtml);
    $this->assertStringContainsString('<h3 class="geo-steps__name"', $stepItemHtml);
  }

  /**
   * Renders a paragraph through the real view builder (suggestion path).
   */
  private function renderParagraph(Paragraph $paragraph): string {
    $build = \Drupal::entityTypeManager()
      ->getViewBuilder('paragraph')
      ->view($paragraph, 'default');
    return (string) \Drupal::service('renderer')->renderRoot($build);
  }

  /**
   * Creates a single-value string field on a paragraph bundle.
   */
  private function createStringField(string $bundle, string $name): void {
    if (!FieldStorageConfig::loadByName('paragraph', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'paragraph',
        'type' => 'string',
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'paragraph',
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Creates a paragraph entity_reference_revisions field on a bundle.
   */
  private function createParagraphRefField(string $bundle, string $name): void {
    if (!FieldStorageConfig::loadByName('paragraph', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'paragraph',
        'type' => 'entity_reference_revisions',
        'cardinality' => -1,
        'settings' => ['target_type' => 'paragraph'],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'paragraph',
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Creates a link field on a paragraph bundle.
   */
  private function createLinkField(string $bundle, string $name): void {
    if (!FieldStorageConfig::loadByName('paragraph', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'paragraph',
        'type' => 'link',
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'paragraph',
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Creates a list_string field on a paragraph bundle.
   */
  private function createListField(string $bundle, string $name, array $allowed): void {
    if (!FieldStorageConfig::loadByName('paragraph', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'paragraph',
        'type' => 'list_string',
        'settings' => ['allowed_values' => $allowed],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'paragraph',
      'bundle' => $bundle,
    ])->save();
  }

}
