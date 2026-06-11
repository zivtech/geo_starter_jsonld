<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld_llms\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Fetches /llms.txt over HTTP and asserts the full crawler-facing contract.
 *
 * The Unit and Kernel suites prove the grammar and the data. What only a real
 * request can prove is the route itself: that an anonymous user gets a 200
 * with the markdown content type, that the document reflects access (an
 * unpublished node never appears), that the cacheability assembled in the
 * builder actually reaches the response headers, and that the anonymous page
 * cache serves the second hit — the load posture this endpoint needs under
 * crawler traffic.
 */
#[Group('geo_starter_jsonld_llms')]
#[RunTestsInSeparateProcesses]
final class LlmsTxtRouteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * file/entity_reference_revisions/paragraphs are not used by this submodule;
   * they are required so the parent geo_starter_jsonld module (a hard
   * dependency) installs cleanly. page_cache is enabled deliberately: the
   * anonymous-crawler cache posture is part of this test's contract.
   */
  protected static $modules = [
    'node',
    'field',
    'text',
    'filter',
    'file',
    'entity_reference_revisions',
    'paragraphs',
    'page_cache',
    'geo_starter_jsonld',
    'geo_starter_jsonld_llms',
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

    // The crawler persona this endpoint exists for.
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    $this->config('system.site')->set('name', 'Functional Site')->save();

    foreach (['service', 'article', 'answer', 'evidence_source'] as $type) {
      NodeType::create(['type' => $type, 'name' => ucfirst($type)])->save();
    }
    $this->createStringField('service', 'field_summary');
    $this->createStringField('article', 'field_summary');
    $this->createStringField('answer', 'field_direct_answer');
    $this->createStringField('evidence_source', 'field_publisher');

    $this->createNode([
      'type' => 'service',
      'title' => 'Crisis support',
      'field_summary' => 'Round-the-clock crisis line.',
    ]);
    $this->createNode([
      'type' => 'article',
      'title' => 'Vaccine guidance',
      'field_summary' => 'What the schedule covers.',
    ]);
    $this->createNode([
      'type' => 'answer',
      'title' => 'How do I appeal?',
      'field_direct_answer' => 'File the appeal form within 30 days.',
    ]);
    $this->createNode([
      'type' => 'evidence_source',
      'title' => 'Immunization schedule',
      'field_publisher' => 'CDC',
    ]);
    $this->createNode([
      'type' => 'article',
      'title' => 'Hidden draft',
      'status' => NodeInterface::NOT_PUBLISHED,
    ]);
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
  private function createNode(array $values): NodeInterface {
    $node = Node::create($values + ['status' => NodeInterface::PUBLISHED]);
    $node->save();
    return $node;
  }

  /**
   * Anonymous GET: status, content type, structure, gating, cache headers.
   */
  public function testAnonymousRequestContract(): void {
    $this->drupalGet('llms.txt');
    $session = $this->assertSession();
    $session->statusCodeEquals(200);
    $session->responseHeaderContains('Content-Type', 'text/markdown; charset=UTF-8');

    $body = $this->getSession()->getPage()->getContent();
    $this->assertStringStartsWith('# Functional Site', $body);
    $this->assertStringContainsString("\n> ", $body);

    // All four governed sections, primary content first, Optional last.
    foreach (['## Services', '## Articles', '## Answers', '## Optional'] as $heading) {
      $this->assertStringContainsString($heading, $body);
    }
    $this->assertGreaterThan(strpos($body, '## Services'), strpos($body, '## Optional'));

    // Entries carry the canonical absolute URL and the governed description.
    $this->assertStringContainsString('Round-the-clock crisis line.', $body);
    $this->assertMatchesRegularExpression('/^- \[Crisis support\]\(http[^)]+\): /m', $body);
    $this->assertMatchesRegularExpression('/^- \[Immunization schedule\]\(http[^)]+\): CDC$/m', $body);

    // The access gate on a real request: unpublished content never appears.
    $this->assertStringNotContainsString('Hidden draft', $body);

    // The builder's cacheability reached the response: list tags for
    // membership, config tags for identity/settings, and the access context.
    $session->responseHeaderContains('X-Drupal-Cache-Tags', 'node_list:article');
    $session->responseHeaderContains('X-Drupal-Cache-Tags', 'config:geo_starter_jsonld_llms.settings');
    $session->responseHeaderContains('X-Drupal-Cache-Contexts', 'user.permissions');
    $session->responseHeaderContains('X-Drupal-Cache-Contexts', 'url.site');
  }

  /**
   * HEAD is served, and the second anonymous GET comes from the page cache.
   */
  public function testHeadAndPageCache(): void {
    $url = $this->buildUrl('llms.txt');

    $head = $this->getHttpClient()->request('HEAD', $url, ['http_errors' => FALSE]);
    $this->assertSame(200, $head->getStatusCode());

    $this->drupalGet('llms.txt');
    $this->drupalGet('llms.txt');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');

    // Editing a listed node invalidates the cached document. The save runs in
    // the test process, which shares the database — and therefore the cache
    // tag invalidation backend — with the child site serving the requests.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => 'Vaccine guidance']);
    $node = reset($nodes);
    $node->setTitle('Vaccine guidance updated')->save();

    $this->drupalGet('llms.txt');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertStringContainsString(
      'Vaccine guidance updated',
      $this->getSession()->getPage()->getContent(),
    );
  }

}
