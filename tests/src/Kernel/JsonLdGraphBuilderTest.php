<?php

declare(strict_types=1);

namespace Drupal\Tests\geo_starter_jsonld\Kernel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\geo_starter_jsonld\Contributor\ParagraphContributorInterface;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\geo_starter_jsonld\JsonLdGraphBuilder;
use Drupal\geo_starter_jsonld\Normalizer\NodeNormalizerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the orchestration contract of JsonLdGraphBuilder in isolation.
 *
 * The builder is constructed directly with anonymous-class normalizer and
 * contributor doubles and the real config.factory, so the assertions exercise
 * the actual orchestration (published guard, WebPage spine, mainEntity linking,
 * one-primary-per-bundle break, contributor merge, script-safe encoding) rather
 * than the field-reading logic, which lives in the normalizers/contributors.
 */
#[CoversClass(JsonLdGraphBuilder::class)]
#[Group('geo_starter_jsonld')]
#[RunTestsInSeparateProcesses]
final class JsonLdGraphBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'geo_starter_jsonld',
  ];

  /**
   * The full-mode view display passed through to normalizers.
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
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->display = \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'page', 'full');
  }

  /**
   * Build a saved node with the given title and published state.
   */
  private function makeNode(string $title, bool $published): NodeInterface {
    $node = Node::create(['type' => 'page', 'title' => $title, 'status' => $published]);
    $node->save();
    return $node;
  }

  /**
   * A normalizer double that emits one primary object with the given fragment.
   */
  private function primaryNormalizer(string $type, string $fragment, string $name = 'Primary'): NodeNormalizerInterface {
    return new class($type, $fragment, $name) implements NodeNormalizerInterface {

      public function __construct(
        private readonly string $type,
        private readonly string $fragment,
        private readonly string $name,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function applies(NodeInterface $node): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function normalize(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
        return [[
          '@type' => $this->type,
          '@id' => $context->canonicalUrl . $this->fragment,
          'name' => $this->name,
        ],
        ];
      }

    };
  }

  /**
   * A normalizer double that fails the test if it is ever invoked.
   */
  private function explodingNormalizer(): NodeNormalizerInterface {
    return new class implements NodeNormalizerInterface {

      /**
       * {@inheritdoc}
       */
      public function applies(NodeInterface $node): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function normalize(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
        throw new \LogicException('Second applying normalizer must not run.');
      }

    };
  }

  /**
   * A contributor double that emits one top-level object.
   */
  private function contributor(string $type, string $fragment): ParagraphContributorInterface {
    return new class($type, $fragment) implements ParagraphContributorInterface {

      public function __construct(
        private readonly string $type,
        private readonly string $fragment,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function applies(NodeInterface $node, EntityViewDisplayInterface $display): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function contribute(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
        return [['@type' => $this->type, '@id' => $context->canonicalUrl . $this->fragment]];
      }

    };
  }

  /**
   * A normalizer double that routes page-level properties via the context.
   *
   * Emits one primary Service and pushes each given property onto the WebPage
   * through JsonLdContext::addWebPageProperty(), mirroring how the real
   * normalizers relocate domain-mismatched metadata (e.g. reviewedBy) to the
   * page spine.
   *
   * @param array<string, mixed> $webPageProperties
   *   The page-level properties to route, keyed by schema.org property name.
   */
  private function pagePropertyNormalizer(array $webPageProperties): NodeNormalizerInterface {
    return new class($webPageProperties) implements NodeNormalizerInterface {

      /**
       * @param array<string, mixed> $webPageProperties
       *   The page-level properties to route.
       */
      public function __construct(
        private readonly array $webPageProperties,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function applies(NodeInterface $node): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function normalize(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
        foreach ($this->webPageProperties as $property => $value) {
          $context->addWebPageProperty($property, $value);
        }
        return [[
          '@type' => 'Service',
          '@id' => $context->canonicalUrl . '#service',
          'name' => 'Primary',
        ],
        ];
      }

    };
  }

  /**
   * Construct a builder with the given doubles and the real config factory.
   *
   * @param \Drupal\geo_starter_jsonld\Normalizer\NodeNormalizerInterface[] $normalizers
   *   The normalizer doubles to inject.
   * @param \Drupal\geo_starter_jsonld\Contributor\ParagraphContributorInterface[] $contributors
   *   The contributor doubles to inject.
   */
  private function builder(array $normalizers = [], array $contributors = []): JsonLdGraphBuilder {
    return new JsonLdGraphBuilder($this->container->get('config.factory'), $normalizers, $contributors);
  }

  /**
   * Decode the @graph from a build() result, or fail.
   *
   * @return array<int, array<string, mixed>>
   *   The decoded @graph array.
   */
  private function graphOf(array $result): array {
    $document = json_decode($result['json'], TRUE);
    $this->assertIsArray($document);
    $this->assertSame('https://schema.org', $document['@context']);
    return $document['@graph'];
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
   * An unpublished node produces no JSON-LD.
   */
  public function testUnpublishedNodeEmitsNothing(): void {
    $node = $this->makeNode('Draft', FALSE);
    $result = $this->builder([$this->primaryNormalizer('Service', '#service')])->build($node, $this->display);
    $this->assertNull($result);
  }

  /**
   * The WebPage spine links the primary entity by @id.
   */
  public function testWebPageSpineLinksPrimaryEntity(): void {
    $node = $this->makeNode('Emergency assistance', TRUE);
    $result = $this->builder([$this->primaryNormalizer('Service', '#service')])->build($node, $this->display);
    $this->assertNotNull($result);
    $graph = $this->graphOf($result);

    $webPage = $this->firstOfType($graph, 'WebPage');
    $service = $this->firstOfType($graph, 'Service');
    $this->assertNotNull($webPage);
    $this->assertNotNull($service);
    // The WebPage is always first and is the spine of the graph.
    $this->assertSame('WebPage', $graph[0]['@type']);
    $this->assertSame($webPage['@id'], $webPage['url']);
    $this->assertSame('Emergency assistance', $webPage['name']);
    // mainEntity links the primary entity by @id, so the graph is connected.
    $this->assertSame($service['@id'], $webPage['mainEntity']['@id']);
    $this->assertStringEndsWith('#service', $service['@id']);
  }

  /**
   * Only the first applying normalizer runs (one primary per node).
   */
  public function testOnlyFirstApplyingNormalizerRuns(): void {
    $node = $this->makeNode('One primary only', TRUE);
    // The second normalizer throws if invoked; reaching the assertions proves
    // the builder broke after the first applying normalizer.
    $result = $this->builder([
      $this->primaryNormalizer('Service', '#service'),
      $this->explodingNormalizer(),
    ])->build($node, $this->display);
    $graph = $this->graphOf($result);
    $this->assertNotNull($this->firstOfType($graph, 'Service'));
  }

  /**
   * Contributor objects are merged into the graph.
   */
  public function testContributorObjectsAreMerged(): void {
    $node = $this->makeNode('With FAQ', TRUE);
    $result = $this->builder(
      [$this->primaryNormalizer('Service', '#service')],
      [$this->contributor('FAQPage', '#faq')],
    )->build($node, $this->display);
    $graph = $this->graphOf($result);
    $this->assertNotNull($this->firstOfType($graph, 'FAQPage'));
    // Spine + primary + contributor.
    $this->assertCount(3, $graph);
  }

  /**
   * With no primary entity the WebPage omits mainEntity.
   */
  public function testNoPrimaryMeansNoMainEntity(): void {
    $node = $this->makeNode('Bare page', TRUE);
    $result = $this->builder()->build($node, $this->display);
    $graph = $this->graphOf($result);
    $webPage = $this->firstOfType($graph, 'WebPage');
    $this->assertNotNull($webPage);
    $this->assertArrayNotHasKey('mainEntity', $webPage);
  }

  /**
   * The payload is hex-escaped so it is safe inside a <script> element.
   */
  public function testPayloadIsHexEscapedForScriptSafety(): void {
    $node = $this->makeNode('Safe', TRUE);
    // A name containing < > & must not appear raw in the <script> payload.
    $result = $this->builder([
      $this->primaryNormalizer('Service', '#service', 'A <b> tag & ampersand'),
    ])->build($node, $this->display);
    $json = $result['json'];
    // No raw markup characters survive into the <script> payload...
    $this->assertStringNotContainsString('<', $json);
    $this->assertStringNotContainsString('&', $json);
    // ...they are hex-escaped instead (JSON_HEX_TAG | JSON_HEX_AMP). The
    // expected escape sequences are computed, not typed, to avoid editor
    // normalization of the backslash-u literal.
    $this->assertStringContainsString(trim(json_encode('<', JSON_HEX_TAG), '"'), $json);
    $this->assertStringContainsString(trim(json_encode('&', JSON_HEX_AMP), '"'), $json);
  }

  /**
   * Routed page-level properties land on the WebPage, not the primary entity.
   */
  public function testContextWebPagePropertiesMergeOntoSpine(): void {
    $node = $this->makeNode('Emergency assistance', TRUE);
    $reviewed_by = ['@type' => 'Person', 'name' => 'Dr. Reviewer'];
    $result = $this->builder([
      $this->pagePropertyNormalizer([
        'about' => [['@type' => 'Thing', 'name' => 'Food assistance']],
        'reviewedBy' => $reviewed_by,
      ]),
    ])->build($node, $this->display);
    $graph = $this->graphOf($result);

    $webPage = $this->firstOfType($graph, 'WebPage');
    $service = $this->firstOfType($graph, 'Service');
    // The routed properties live on the page...
    $this->assertSame($reviewed_by, $webPage['reviewedBy']);
    $this->assertSame('Food assistance', $webPage['about'][0]['name']);
    // ...and never leak onto the primary entity.
    $this->assertArrayNotHasKey('reviewedBy', $service);
    $this->assertArrayNotHasKey('about', $service);
    // The spine still connects the graph by @id.
    $this->assertSame($service['@id'], $webPage['mainEntity']['@id']);
  }

  /**
   * Spine keys win: a routed property cannot overwrite @id / url / name.
   */
  public function testSpineKeysWinOverRoutedProperties(): void {
    $node = $this->makeNode('Canonical name', TRUE);
    $result = $this->builder([
      $this->pagePropertyNormalizer([
        'name' => 'Hijacked name',
        'url' => 'https://evil.example/phishing',
      ]),
    ])->build($node, $this->display);
    $graph = $this->graphOf($result);

    $webPage = $this->firstOfType($graph, 'WebPage');
    // The node title and canonical URL win over anything a normalizer routes.
    $this->assertSame('Canonical name', $webPage['name']);
    $this->assertSame($webPage['@id'], $webPage['url']);
    $this->assertStringStartsWith('http', $webPage['url']);
    $this->assertStringNotContainsString('evil.example', $webPage['url']);
  }

}
