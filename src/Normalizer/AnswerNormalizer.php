<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld\Normalizer;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\geo_starter_jsonld\JsonLdFieldTrait;
use Drupal\node\NodeInterface;

/**
 * Normalizes an Answer node to a schema.org Question + acceptedAnswer.
 *
 * The marquee GEO pattern for Answer pages (jsonld plan §2.2): the page's title
 * is the question, and field_direct_answer is the single canonical answer. A
 * broad FAQPage is deliberately NOT emitted here — that is the gated
 * FaqContributor's job, and only when faqpage_on_answer is enabled (default off).
 */
final class AnswerNormalizer implements NodeNormalizerInterface {

  use JsonLdFieldTrait;

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node): bool {
    return $node->bundle() === 'answer';
  }

  /**
   * {@inheritdoc}
   */
  public function normalize(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
    $question = [
      '@type' => 'Question',
      '@id' => $context->canonicalUrl . '#answer',
      'name' => $node->label(),
    ];

    if ($this->hasValue($node, $display, 'field_direct_answer')) {
      $text = $this->plainText((string) $node->get('field_direct_answer')->value);
      if ($text !== '') {
        $answer = ['@type' => 'Answer', 'text' => $text];
        $citations = $this->resolveCitations($node, $display, 'field_evidence_sources', $context);
        if ($citations !== []) {
          $answer['citation'] = $citations;
        }
        $question['acceptedAnswer'] = $answer;
      }
    }

    if ($this->hasValue($node, $display, 'field_reviewed_date')) {
      $question['dateModified'] = $this->isoDate((string) $node->get('field_reviewed_date')->value);
    }

    $question += $this->schemaReviewedBy($node, $display, $context);

    $about = $this->schemaAbout($node, $display, 'field_topic', $context);
    if ($about !== []) {
      $question['about'] = $about;
    }
    $audience = $this->schemaAudience($node, $display, 'field_audience', $context);
    if ($audience !== []) {
      $question['audience'] = $audience;
    }

    return [$question];
  }

}
