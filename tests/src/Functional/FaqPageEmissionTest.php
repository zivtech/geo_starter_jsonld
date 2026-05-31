<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Functional;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Renders real Service pages and asserts the emitted JSON-LD on the response.
 *
 * The Kernel suite (FaqContributorTest) exercises the FAQPage content gate
 * against the contributor in isolation. What it cannot reach is the part that
 * only exists on a real request: that hook_node_view_alter() actually attaches
 * the <script type="application/ld+json"> to the page head, that the published
 * guard runs on the canonical route, and that exactly one graph is emitted.
 * This test fills exactly that gap by building the content model from scratch
 * (no recipe coupling), rendering the node through the full pipeline, and
 * decoding the script tag from the response HTML.
 *
 * Scope note (negative space): the view-mode / non-subject arms of guard #5
 * (teaser, embed, preview) are covered at the unit/kernel level and by the
 * single-script assertion here; this class deliberately does not stand up a
 * listing View to re-test them, to avoid a profile-dependent fixture.
 */
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class FaqPageEmissionTest extends BrowserTestBase {

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
    ParagraphsType::create(['id' => 'section_faq', 'label' => 'FAQ'])->save();
    ParagraphsType::create(['id' => 'section_faq_item', 'label' => 'FAQ item'])->save();

    $this->createStringField('paragraph', 'section_faq_item', 'field_section_question');
    $this->createStringField('paragraph', 'section_faq_item', 'field_section_answer');
    $this->createParagraphRefField('paragraph', 'section_faq', 'field_section_items');
    $this->createParagraphRefField('node', 'service', 'field_sections');

    // Enable a real 'full' display that renders field_sections, so the page
    // the anonymous visitor sees shows the FAQ and the contributor's parity
    // guard (field_sections visible) passes on the rendered route.
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

    // Paragraph sub-displays so the Q&A is visibly rendered (parity), not just
    // present in the structured data the contributor reads from field values.
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
   * Build a service node carrying one section_faq of Q/A pairs.
   *
   * @param array<int, array{0: string, 1: string}> $pairs
   *   Each pair is [question, answer]; '' marks a deliberately empty side.
   * @param int $status
   *   Node publication status; defaults to published.
   */
  private function serviceWithFaq(array $pairs, int $status = NodeInterface::PUBLISHED): NodeInterface {
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
      'status' => $status,
      'field_sections' => [['entity' => $faq]],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Decode every application/ld+json script block on the current response.
   *
   * Mink's getText() returns '' for <script> in the non-JS BrowserKit driver,
   * so parse the raw response HTML directly. Returns each decoded document.
   *
   * @return array<int, mixed>
   *   Each decoded JSON-LD document found on the page.
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
   * Pull the single object of a given @type out of a JSON-LD @graph document.
   */
  private function graphObject(array $document, string $type): ?array {
    foreach ($document['@graph'] ?? [] as $object) {
      if (($object['@type'] ?? NULL) === $type) {
        return $object;
      }
    }
    return NULL;
  }

  /**
   * Two valid pairs on a published Service emit a correct, connected graph.
   */
  public function testCanonicalServicePageEmitsFaqPage(): void {
    $node = $this->serviceWithFaq([['Q one', 'A one'], ['Q two', 'A two']]);
    $canonical = $node->toUrl('canonical', ['absolute' => TRUE])->toString();

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    // Parity: the visible Q&A actually rendered on the page.
    $this->assertSession()->pageTextContains('Q one');
    $this->assertSession()->pageTextContains('A one');

    $documents = $this->jsonLdDocuments();
    // Exactly one graph on the canonical page — no duplicate from any embed.
    $this->assertCount(1, $documents, 'A single JSON-LD graph is emitted.');
    $document = $documents[0];
    $this->assertSame('https://schema.org', $document['@context']);

    // The WebPage spine links to the Service by @id.
    $webPage = $this->graphObject($document, 'WebPage');
    $this->assertNotNull($webPage, 'A WebPage node is present.');
    $this->assertSame($canonical, $webPage['@id']);
    $this->assertSame($canonical . '#service', $webPage['mainEntity']['@id']);

    // The Service primary entity is present at its fragment @id.
    $service = $this->graphObject($document, 'Service');
    $this->assertNotNull($service, 'A Service node is present.');
    $this->assertSame($canonical . '#service', $service['@id']);
    $this->assertSame('Emergency assistance', $service['name']);

    // The marquee object: a FAQPage with two well-formed Questions.
    $faqPage = $this->graphObject($document, 'FAQPage');
    $this->assertNotNull($faqPage, 'A FAQPage node is present.');
    $this->assertSame($canonical . '#faq', $faqPage['@id']);
    $this->assertCount(2, $faqPage['mainEntity']);
    $this->assertSame('Question', $faqPage['mainEntity'][0]['@type']);
    $this->assertSame('Q one', $faqPage['mainEntity'][0]['name']);
    $this->assertSame('A one', $faqPage['mainEntity'][0]['acceptedAnswer']['text']);
    $this->assertSame('Answer', $faqPage['mainEntity'][0]['acceptedAnswer']['@type']);
    $this->assertSame('Q two', $faqPage['mainEntity'][1]['name']);
  }

  /**
   * A single valid pair is below the minimum: Service emits, FAQPage does not.
   */
  public function testThinFaqEmitsServiceButNoFaqPage(): void {
    $node = $this->serviceWithFaq([['Only question', 'Only answer']]);

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    $documents = $this->jsonLdDocuments();
    $this->assertCount(1, $documents);
    // The page still emits its Service/WebPage graph...
    $this->assertNotNull($this->graphObject($documents[0], 'Service'));
    // ...but the thin FAQ is withheld rather than emitted as weak markup.
    $this->assertNull($this->graphObject($documents[0], 'FAQPage'));
  }

  /**
   * Guard #1 on a real request: an unpublished node emits no JSON-LD at all.
   */
  public function testUnpublishedServiceEmitsNoJsonLd(): void {
    $node = $this->serviceWithFaq(
      [['Q one', 'A one'], ['Q two', 'A two']],
      NodeInterface::NOT_PUBLISHED,
    );

    // View as a user allowed to see the unpublished canonical page, so a 403
    // does not mask the real assertion (that nothing is emitted on guard #1).
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'view own unpublished content',
    ]));
    // The created user owns nothing; make it the author so it can view it.
    $node->setOwnerId((int) $this->loggedInUser->id())->save();

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(0, $this->jsonLdDocuments(), 'Unpublished nodes emit no JSON-LD.');
  }

}
