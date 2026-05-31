<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld\Contributor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\geo_starter_jsonld\JsonLdFieldTrait;
use Drupal\node\NodeInterface;

/**
 * Emits a gated schema.org FAQPage from section_faq paragraphs.
 *
 * The marquee GEO pattern. Invalid or thin FAQ markup is worse than none, so a
 * FAQPage is emitted ONLY when the content-based gate passes (jsonld plan §3):
 * at least two section_faq_item children whose question AND answer are both
 * non-empty after stripping, on a published node of an enabled bundle whose
 * per-bundle flag is on. Questions are aggregated across every section_faq
 * instance into one FAQPage; order follows paragraph delta. When the gate
 * fails the visible Q&A still renders — only the structured object is withheld.
 */
final class FaqContributor implements ParagraphContributorInterface {

  use JsonLdFieldTrait;

  /**
   * Minimum valid Q&A pairs required to emit a FAQPage.
   */
  private const MINIMUM_PAIRS = 2;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node, EntityViewDisplayInterface $display): bool {
    $flag = match ($node->bundle()) {
      'service' => 'faqpage_on_service',
      'answer' => 'faqpage_on_answer',
      default => NULL,
    };
    if ($flag === NULL) {
      return FALSE;
    }
    // Parity: the paragraphs only emit if the section host field is rendered.
    if (!$this->isVisible($display, 'field_sections')) {
      return FALSE;
    }
    return (bool) $this->configFactory->get('geo_starter_jsonld.settings')->get($flag);
  }

  /**
   * {@inheritdoc}
   */
  public function contribute(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
    if (!$node->hasField('field_sections') || $node->get('field_sections')->isEmpty()) {
      return [];
    }

    $questions = [];
    $position = 0;

    foreach ($node->get('field_sections')->referencedEntities() as $section) {
      if ($section->bundle() !== 'section_faq') {
        continue;
      }
      $context->cacheability->addCacheableDependency($section);
      if (!$section->hasField('field_section_items') || $section->get('field_section_items')->isEmpty()) {
        continue;
      }

      foreach ($section->get('field_section_items')->referencedEntities() as $item) {
        if ($item->bundle() !== 'section_faq_item') {
          continue;
        }
        $context->cacheability->addCacheableDependency($item);

        $question = $this->itemText($item, 'field_section_question');
        $answer = $this->itemText($item, 'field_section_answer');
        // Gate: count only pairs with BOTH sides non-empty after stripping.
        if ($question === '' || $answer === '') {
          continue;
        }

        $position++;
        $questions[] = [
          '@type' => 'Question',
          '@id' => $context->canonicalUrl . '#faq-q' . $position,
          'name' => $question,
          'acceptedAnswer' => [
            '@type' => 'Answer',
            'text' => $answer,
          ],
        ];
      }
    }

    if (count($questions) < self::MINIMUM_PAIRS) {
      return [];
    }

    return [
      [
        '@type' => 'FAQPage',
        '@id' => $context->canonicalUrl . '#faq',
        'mainEntity' => $questions,
      ],
    ];
  }

  /**
   * Read and strip a paragraph item's text field, or '' when empty/missing.
   */
  private function itemText(FieldableEntityInterface $item, string $field): string {
    if (!$item->hasField($field) || $item->get($field)->isEmpty()) {
      return '';
    }
    return $this->plainText((string) $item->get($field)->value);
  }

}
