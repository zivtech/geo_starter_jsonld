<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld_llms\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\geo_starter_jsonld_llms\LlmsTxtBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel-tests the builder: query gating, description sources, cacheability.
 *
 * The Unit suite proves the markdown grammar; this suite proves the builder
 * feeds it the right data — published-and-accessible nodes only, the correct
 * governed description field per bundle, deterministic order, the section
 * cap — and that the returned cache metadata carries every tag and context
 * the response needs to invalidate correctly (the feature's highest-risk
 * surface).
 */
#[CoversClass(LlmsTxtBuilder::class)]
#[Group('geo_starter_jsonld_llms')]
#[RunTestsInSeparateProcesses]
final class LlmsTxtBuilderTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The file/entity_reference_revisions/paragraphs modules are not used by
   * this submodule; they are required so the parent geo_starter_jsonld module
   * (a hard dependency whose container wiring this test boots) builds
   * cleanly.
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
    'geo_starter_jsonld_llms',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'geo_starter_jsonld', 'geo_starter_jsonld_llms']);

    foreach (['service', 'article', 'answer', 'evidence_source'] as $type) {
      NodeType::create(['type' => $type, 'name' => ucfirst($type)])->save();
    }
    $this->createStringField('service', 'field_summary');
    $this->createStringField('article', 'field_summary');
    $this->createStringField('answer', 'field_direct_answer');
    $this->createStringField('evidence_source', 'field_publisher');

    $this->config('system.site')->set('name', 'Kernel Site')->set('slogan', '')->save();

    // The builder queries with accessCheck(TRUE), so run as a plain
    // content-viewing user — the same access level as an anonymous crawler.
    $this->setUpCurrentUser([], ['access content']);
  }

  /**
   * Create a single-value string field on a node bundle.
   */
  private function createStringField(string $bundle, string $name): void {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'string',
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Create a node with sane defaults.
   *
   * @param array<string, mixed> $values
   *   Node values; 'type' and 'title' are required by callers.
   */
  private function createGovernedNode(array $values): NodeInterface {
    $node = Node::create($values + ['status' => NodeInterface::PUBLISHED]);
    $node->save();
    return $node;
  }

  /**
   * Shortcut to the builder service's rendered markdown.
   */
  private function markdown(): string {
    return \Drupal::service('geo_starter_jsonld_llms.builder')->build()['markdown'];
  }

  /**
   * Unpublished nodes never appear; published ones do.
   */
  public function testPublishedOnly(): void {
    $this->createGovernedNode(['type' => 'article', 'title' => 'Visible article']);
    $this->createGovernedNode([
      'type' => 'article',
      'title' => 'Hidden draft',
      'status' => NodeInterface::NOT_PUBLISHED,
    ]);

    $markdown = $this->markdown();
    $this->assertStringContainsString('Visible article', $markdown);
    $this->assertStringNotContainsString('Hidden draft', $markdown);
  }

  /**
   * Each bundle's description comes from its governed field.
   */
  public function testDescriptionSourcePerBundle(): void {
    $this->createGovernedNode([
      'type' => 'service',
      'title' => 'Crisis support',
      'field_summary' => 'Round-the-clock crisis line.',
    ]);
    $this->createGovernedNode([
      'type' => 'article',
      'title' => 'Vaccine guidance',
      'field_summary' => 'What the schedule covers.',
    ]);
    $this->createGovernedNode([
      'type' => 'answer',
      'title' => 'How do I appeal?',
      'field_direct_answer' => 'File the appeal form within 30 days.',
    ]);
    $this->createGovernedNode([
      'type' => 'evidence_source',
      'title' => 'Immunization schedule',
      'field_publisher' => 'CDC',
    ]);

    $markdown = $this->markdown();
    $this->assertMatchesRegularExpression('/^- \[Crisis support\]\(http[^)]+\): Round-the-clock crisis line\.$/m', $markdown);
    $this->assertMatchesRegularExpression('/^- \[Vaccine guidance\]\(http[^)]+\): What the schedule covers\.$/m', $markdown);
    $this->assertMatchesRegularExpression('/^- \[How do I appeal\?\]\(http[^)]+\): File the appeal form within 30 days\.$/m', $markdown);
    $this->assertMatchesRegularExpression('/^- \[Immunization schedule\]\(http[^)]+\): CDC$/m', $markdown);
  }

  /**
   * Evidence Sources sit under the spec's literal "Optional" heading, last.
   */
  public function testEvidenceSourcesUnderOptionalSection(): void {
    $this->createGovernedNode(['type' => 'service', 'title' => 'Crisis support']);
    $evidence = $this->createGovernedNode([
      'type' => 'evidence_source',
      'title' => 'Immunization schedule',
      'field_publisher' => 'CDC',
    ]);

    $markdown = $this->markdown();
    $this->assertStringContainsString('## Optional', $markdown);
    $this->assertStringNotContainsString('## Sources', $markdown);
    $this->assertGreaterThan(
      strpos($markdown, '## Services'),
      strpos($markdown, '## Optional'),
      'The Optional section trails the primary content sections.',
    );
    // The link is the node's own canonical URL (the on-site CreativeWork
    // declaration), never an off-site source URL.
    $this->assertStringContainsString(
      $evidence->toUrl('canonical', ['absolute' => TRUE])->toString(),
      $markdown,
    );
  }

  /**
   * A node with an empty description field emits a bare link.
   *
   * Absent beats wrong.
   */
  public function testMissingDescriptionDegradesGracefully(): void {
    $this->createGovernedNode(['type' => 'article', 'title' => 'No summary article']);

    $this->assertMatchesRegularExpression(
      '/^- \[No summary article\]\(http[^)]+\)$/m',
      $this->markdown(),
    );
  }

  /**
   * The cache metadata carries every tag and context the response needs.
   */
  public function testCacheMetadata(): void {
    $node = $this->createGovernedNode(['type' => 'article', 'title' => 'Tagged article']);

    $cacheability = \Drupal::service('geo_starter_jsonld_llms.builder')->build()['cacheability'];
    $tags = $cacheability->getCacheTags();

    // Membership invalidation for every governed bundle — including the three
    // EMPTY ones, so creating the first node of a bundle invalidates the doc.
    foreach (['service', 'article', 'answer', 'evidence_source'] as $bundle) {
      $this->assertContains('node_list:' . $bundle, $tags);
    }
    // Content-of-listed-node invalidation.
    $this->assertContains('node:' . $node->id(), $tags);
    // Site identity (name/slogan) and the submodule's own settings.
    $this->assertContains('config:system.site', $tags);
    $this->assertContains('config:geo_starter_jsonld_llms.settings', $tags);

    $contexts = $cacheability->getCacheContexts();
    $this->assertContains('url.site', $contexts);
    // accessCheck(TRUE) makes the listing access-dependent; without this
    // context one account's listing could be served to another.
    $this->assertContains('user.permissions', $contexts);
  }

  /**
   * The summary blockquote falls back: setting, then slogan, then generic.
   */
  public function testSummaryFallbackChain(): void {
    $this->config('geo_starter_jsonld_llms.settings')->set('site_summary', 'Configured summary.')->save();
    $this->assertStringContainsString('> Configured summary.', $this->markdown());

    $this->config('geo_starter_jsonld_llms.settings')->set('site_summary', '')->save();
    $this->config('system.site')->set('slogan', 'Slogan line.')->save();
    $this->assertStringContainsString('> Slogan line.', $this->markdown());

    $this->config('system.site')->set('slogan', '')->save();
    $markdown = $this->markdown();
    $this->assertStringContainsString('> Kernel Site — governed, sourced content', $markdown);
  }

  /**
   * Zero content still yields a valid document: H1 + blockquote, no sections.
   */
  public function testEmptyContentStillValidDocument(): void {
    $markdown = $this->markdown();

    $this->assertStringStartsWith("# Kernel Site\n", $markdown);
    $this->assertStringContainsString("\n> ", $markdown);
    $this->assertStringNotContainsString('## ', $markdown);
  }

  /**
   * The per-section cap bounds each section deterministically by title order.
   */
  public function testPerSectionCap(): void {
    foreach (['Alpha service', 'Beta service', 'Gamma service'] as $title) {
      $this->createGovernedNode(['type' => 'service', 'title' => $title]);
    }

    $builder = new LlmsTxtBuilder(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      2,
    );
    $markdown = $builder->build()['markdown'];

    $this->assertStringContainsString('Alpha service', $markdown);
    $this->assertStringContainsString('Beta service', $markdown);
    $this->assertStringNotContainsString('Gamma service', $markdown);
  }

}
