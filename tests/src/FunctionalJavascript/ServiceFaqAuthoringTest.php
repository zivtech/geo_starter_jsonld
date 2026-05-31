<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\FunctionalJavascript;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Authors FAQ paragraphs through the real node form, then checks emission.
 *
 * This is the end-to-end round trip the Kernel and (non-JS) Functional tests
 * cannot reach: an editor opens the Service node form, uses the Paragraphs
 * AJAX "Add" widget to build a section_faq with two Q&A items, saves, and the
 * published page emits a JSON-LD FAQPage. It needs a real browser because the
 * Paragraphs add/remove buttons are AJAX — a non-JS driver never builds the
 * nested subforms. Content model is built from scratch (no recipe coupling);
 * the module's own emission logic is exercised against editor-authored content.
 */
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class ServiceFaqAuthoringTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'text',
    'filter',
    'file',
    'entity_reference_revisions',
    'paragraphs',
    'geo_starter_jsonld',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    NodeType::create(['type' => 'service', 'name' => 'Service'])->save();
    // Distinct labels keep the two AJAX "Add" buttons unambiguous to pressButton().
    ParagraphsType::create(['id' => 'section_faq', 'label' => 'FAQ Section'])->save();
    ParagraphsType::create(['id' => 'section_faq_item', 'label' => 'FAQ Pair'])->save();

    $this->createStringField('paragraph', 'section_faq_item', 'field_section_question', 'Question');
    $this->createStringField('paragraph', 'section_faq_item', 'field_section_answer', 'Answer');
    $this->createParagraphRefField('paragraph', 'section_faq', 'field_section_items', ['section_faq_item']);
    $this->createParagraphRefField('node', 'service', 'field_sections', ['section_faq']);

    $this->configureFormDisplays();
    $this->configureViewDisplays();
  }

  /**
   * Create a single-value string field on a bundle.
   */
  private function createStringField(string $entityType, string $bundle, string $name, string $label): void {
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
      'label' => $label,
    ])->save();
  }

  /**
   * Create a paragraph reference field restricted to specific target bundles.
   *
   * @param string[] $targetBundles
   *   The allowed paragraph bundles, so the widget shows one "Add" button.
   */
  private function createParagraphRefField(string $entityType, string $bundle, string $name, array $targetBundles): void {
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
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => array_combine($targetBundles, $targetBundles),
          'negate' => 0,
        ],
      ],
    ])->save();
  }

  /**
   * Form displays: the Paragraphs widget (button add mode) at both levels.
   */
  private function configureFormDisplays(): void {
    EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'service',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_sections', [
      'type' => 'paragraphs',
      'settings' => ['edit_mode' => 'open', 'add_mode' => 'button'],
    ])->save();

    EntityFormDisplay::create([
      'targetEntityType' => 'paragraph',
      'bundle' => 'section_faq',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_section_items', [
      'type' => 'paragraphs',
      'settings' => ['edit_mode' => 'open', 'add_mode' => 'button'],
    ])->save();

    EntityFormDisplay::create([
      'targetEntityType' => 'paragraph',
      'bundle' => 'section_faq_item',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_section_question', ['type' => 'string_textfield'])
      ->setComponent('field_section_answer', ['type' => 'string_textfield'])
      ->save();
  }

  /**
   * View displays: render field_sections in full so emission fires on view.
   */
  private function configureViewDisplays(): void {
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'service',
      'mode' => 'full',
      'status' => TRUE,
    ])->setComponent('field_sections', [
      'type' => 'entity_reference_revisions_entity_view',
      'settings' => ['view_mode' => 'default'],
      'label' => 'hidden',
    ])->save();

    EntityViewDisplay::create([
      'targetEntityType' => 'paragraph',
      'bundle' => 'section_faq',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_section_items', [
      'type' => 'entity_reference_revisions_entity_view',
      'settings' => ['view_mode' => 'default'],
      'label' => 'hidden',
    ])->save();

    EntityViewDisplay::create([
      'targetEntityType' => 'paragraph',
      'bundle' => 'section_faq_item',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_section_question', ['type' => 'string', 'label' => 'hidden'])
      ->setComponent('field_section_answer', ['type' => 'string', 'label' => 'hidden'])
      ->save();
  }

  /**
   * Decode every application/ld+json script block in the current DOM.
   *
   * @return array<int, mixed>
   */
  private function jsonLdDocuments(): array {
    $html = $this->getSession()->getPage()->getContent();
    $previous = libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $documents = [];
    foreach ($dom->getElementsByTagName('script') as $script) {
      if ($script->getAttribute('type') === 'application/ld+json') {
        $documents[] = json_decode($script->textContent, TRUE);
      }
    }
    return $documents;
  }

  /**
   * Fill the nth occurrence (0-indexed) of a deeply-nested subform field.
   *
   * Question/Answer inputs repeat once per FAQ item, so they cannot be matched
   * by label; target them by the name-suffix the widget renders.
   */
  private function fillNestedBySuffix(string $suffix, int $index, string $value): void {
    $inputs = $this->getSession()->getPage()->findAll('css', 'input[name$="' . $suffix . '"]');
    $this->assertArrayHasKey($index, $inputs, "Expected a nested input #$index matching $suffix.");
    $inputs[$index]->setValue($value);
  }

  /**
   * Author two FAQ pairs through the AJAX widget; the saved page emits FAQPage.
   */
  public function testAuthoringFaqThroughTheFormEmitsFaqPage(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'create service content',
      'edit own service content',
    ]));

    $this->drupalGet('node/add/service');
    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();

    $page->fillField('Title', 'Emergency assistance');

    // Add the FAQ section (AJAX), then two FAQ pairs inside it (AJAX each).
    $page->pressButton('Add FAQ Section');
    $assert->assertWaitOnAjaxRequest();
    $page->pressButton('Add FAQ Pair');
    $assert->assertWaitOnAjaxRequest();
    $page->pressButton('Add FAQ Pair');
    $assert->assertWaitOnAjaxRequest();

    // Two question inputs and two answer inputs now exist in the nested subform.
    $this->fillNestedBySuffix('[field_section_question][0][value]', 0, 'Q one');
    $this->fillNestedBySuffix('[field_section_answer][0][value]', 0, 'A one');
    $this->fillNestedBySuffix('[field_section_question][0][value]', 1, 'Q two');
    $this->fillNestedBySuffix('[field_section_answer][0][value]', 1, 'A two');

    $page->pressButton('Save');
    // WebDriver has no HTTP status; landing on the node page (its title shown)
    // is the signal the save succeeded and we are on the canonical view.
    $assert->waitForText('Emergency assistance');
    $assert->pageTextContains('Emergency assistance');

    // The published page the editor just created emits a correct FAQPage.
    $documents = $this->jsonLdDocuments();
    $this->assertCount(1, $documents, 'A single JSON-LD graph is emitted.');

    $faqPage = NULL;
    foreach ($documents[0]['@graph'] ?? [] as $object) {
      if (($object['@type'] ?? NULL) === 'FAQPage') {
        $faqPage = $object;
        break;
      }
    }
    $this->assertNotNull($faqPage, 'Editor-authored FAQ produced a FAQPage.');
    $this->assertCount(2, $faqPage['mainEntity']);
    $this->assertSame('Q one', $faqPage['mainEntity'][0]['name']);
    $this->assertSame('A one', $faqPage['mainEntity'][0]['acceptedAnswer']['text']);
    $this->assertSame('Q two', $faqPage['mainEntity'][1]['name']);
  }

}
