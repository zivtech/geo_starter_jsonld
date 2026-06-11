<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld_llms;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the /llms.txt markdown document over the four governed bundles.
 *
 * The site-level companion to the parent module's per-page JSON-LD: where the
 * graph answers "what is this page", this index answers "what does this site
 * offer". The parity invariant for a public site index is access, not display:
 * every entry links to a page the requesting user could itself fetch
 * (access-checked, published-only queries), and every description is the
 * plain-text projection of that bundle's governed description field — the
 * same fields the JSON-LD normalizers read. Per-node view-display gating is
 * deliberately NOT applied here: an index is not a render of any single page,
 * so there is no rendered HTML for it to be in byte-parity with.
 */
final class LlmsTxtBuilder {

  /**
   * Fail-safe per-section entry cap.
   *
   * Governed GEO Starter sites run tens of nodes, so this is never reached in
   * practice; it exists to bound the response size if a site's content set
   * runs away. Silent by design — the llms.txt spec has no pagination notion.
   */
  private const PER_SECTION_CAP = 500;

  /**
   * The governed bundles, in emission order, with their description fields.
   *
   * Section titles are product copy, not config. Description fields mirror
   * the JSON-LD normalizers' description sources (ServiceNormalizer and
   * ArticleNormalizer read field_summary, AnswerNormalizer reads
   * field_direct_answer, EvidenceSourceNormalizer reads field_publisher).
   * Evidence Sources sit under the spec's literal "Optional" heading — the
   * one heading llms.txt assigns machine semantics to (skippable when an
   * agent needs shorter context), which is exactly the role of secondary
   * citation-resolution targets.
   */
  private const SECTIONS = [
    ['bundle' => 'service', 'title' => 'Services', 'description_field' => 'field_summary'],
    ['bundle' => 'article', 'title' => 'Articles', 'description_field' => 'field_summary'],
    ['bundle' => 'answer', 'title' => 'Answers', 'description_field' => 'field_direct_answer'],
    ['bundle' => 'evidence_source', 'title' => 'Optional', 'description_field' => 'field_publisher'],
  ];

  /**
   * Constructs an LlmsTxtBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for access-checked node queries.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the site identity and the summary setting.
   * @param int $perSectionCap
   *   Maximum entries per section; overridable for tests only.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly int $perSectionCap = self::PER_SECTION_CAP,
  ) {}

  /**
   * Build the llms.txt document and its cache metadata.
   *
   * @return array{markdown: string, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The rendered markdown and the metadata the response must carry.
   */
  public function build(): array {
    $cacheability = new CacheableMetadata();
    // url.site: the H1 and every link embed the absolute host.
    // user.permissions: accessCheck(TRUE) makes the listing access-dependent,
    // so the cache must vary by permission set or one account's listing would
    // be served to another.
    $cacheability->addCacheContexts(['url.site', 'user.permissions']);

    $site = $this->configFactory->get('system.site');
    $cacheability->addCacheableDependency($site);
    $settings = $this->configFactory->get('geo_starter_jsonld_llms.settings');
    $cacheability->addCacheableDependency($settings);

    $siteName = trim((string) $site->get('name'));
    $siteName = $siteName === '' ? 'Site' : $siteName;

    $sections = [];
    foreach (self::SECTIONS as $definition) {
      $sections[] = [
        'title' => $definition['title'],
        'entries' => $this->sectionEntries($definition['bundle'], $definition['description_field'], $cacheability),
      ];
    }

    $document = new LlmsTxtDocument($siteName, $this->resolveSummary($settings, $site, $siteName), $sections);
    return ['markdown' => $document->render(), 'cacheability' => $cacheability];
  }

  /**
   * Query one bundle and map its nodes to document entries.
   *
   * @param string $bundle
   *   The node bundle to list.
   * @param string $descriptionField
   *   The bundle's governed description field.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Accumulates the document's cache metadata; mutated by this method
   *   (the bundle list tag, each listed node, and each generated URL).
   *
   * @return array<int, array{text: string, url: string, description: string}>
   *   The raw entry primitives, in title order.
   */
  private function sectionEntries(string $bundle, string $descriptionField, CacheableMetadata $cacheability): array {
    // Membership invalidation: bubble the per-bundle list tag even when the
    // bundle is empty, so creating the FIRST node of a bundle invalidates
    // this document. Bubbling only when nodes exist would be the subtle bug.
    $cacheability->addCacheTags(['node_list:' . $bundle]);

    $storage = $this->entityTypeManager->getStorage('node');
    // accessCheck(TRUE) gates the listing to what the requesting user may
    // view — the real parity guarantee for a public index. The explicit
    // status condition is a fail-closed second lock that keeps the published
    // invariant legible (it mirrors the parent builder's isPublished guard).
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('type', $bundle)
      ->sort('title')
      ->sort('nid')
      ->range(0, $this->perSectionCap)
      ->execute();

    $entries = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $cacheability->addCacheableDependency($node);
      $generated = $node->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
      $cacheability->addCacheableDependency($generated);
      $entries[] = [
        'text' => (string) $node->label(),
        'url' => $generated->getGeneratedUrl(),
        'description' => $this->rawDescription($node, $descriptionField),
      ];
    }
    return $entries;
  }

  /**
   * Raw governed description value, or '' — absent beats wrong.
   *
   * Reads the first item's scalar value property, which is the stored text
   * for every governed description field (string, string_long, text_long).
   * A non-text field in a description slot degrades to '' rather than error.
   */
  private function rawDescription(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    return (string) $node->get($field)->value;
  }

  /**
   * Resolve the blockquote summary: setting, then slogan, then generic line.
   *
   * The llms.txt spec expects a blockquote summary, so an unconfigured site
   * still gets a truthful generic line instead of a malformed document.
   */
  private function resolveSummary(ImmutableConfig $settings, ImmutableConfig $site, string $siteName): string {
    $configured = trim((string) $settings->get('site_summary'));
    if ($configured !== '') {
      return $configured;
    }
    $slogan = trim((string) $site->get('slogan'));
    if ($slogan !== '') {
      return $slogan;
    }
    return $siteName . ' — governed, sourced content for retrieval systems and answer engines.';
  }

}
