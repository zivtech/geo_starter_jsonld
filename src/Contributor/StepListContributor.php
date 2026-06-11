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
 * Emits a gated schema.org HowTo from section_step_list paragraphs.
 *
 * Content-based gate (jsonld plan §3): emit a HowTo only when at least two
 * section_step_item children have a non-empty step name AND step text after
 * stripping, on a published node, with the emit_howto setting on. Step order
 * follows paragraph delta. Google deprecated the HowTo rich result for display,
 * but the markup stays valid for retrieval/agent surfaces — we emit it for
 * machine-readability and make no rich-result claim.
 *
 * The optional step image is intentionally NOT emitted yet (a field may be
 * present but not rendered); omitting it is parity-safe.
 */
final class StepListContributor implements ParagraphContributorInterface {

  use JsonLdFieldTrait;

  /**
   * Minimum valid steps required to emit a HowTo.
   */
  private const MINIMUM_STEPS = 2;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node, EntityViewDisplayInterface $display): bool {
    if (!$node->hasField('field_sections') || !$this->isVisible($display, 'field_sections')) {
      return FALSE;
    }
    return (bool) $this->configFactory->get('geo_starter_jsonld.settings')->get('emit_howto');
  }

  /**
   * {@inheritdoc}
   */
  public function contribute(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
    if (!$node->hasField('field_sections') || $node->get('field_sections')->isEmpty()) {
      return [];
    }

    $steps = [];
    $position = 0;
    $howtoName = '';

    foreach ($node->get('field_sections')->referencedEntities() as $section) {
      if ($section->bundle() !== 'section_step_list') {
        continue;
      }
      $context->cacheability->addCacheableDependency($section);
      // The HowTo name is the step-list heading (Google requires HowTo.name; it
      // is also better LLM context). First non-empty heading wins; absent is
      // parity-safe — emit a nameless HowTo rather than fabricate one.
      if ($howtoName === '' && $section->hasField('field_section_heading') && !$section->get('field_section_heading')->isEmpty()) {
        $howtoName = $this->plainText((string) $section->get('field_section_heading')->value);
      }
      if (!$section->hasField('field_section_steps') || $section->get('field_section_steps')->isEmpty()) {
        continue;
      }

      foreach ($section->get('field_section_steps')->referencedEntities() as $item) {
        if ($item->bundle() !== 'section_step_item') {
          continue;
        }
        $context->cacheability->addCacheableDependency($item);

        $stepName = $this->stepText($item, 'field_section_step_name');
        $text = $this->stepText($item, 'field_section_step_text');
        if ($stepName === '' || $text === '') {
          continue;
        }

        $position++;
        $steps[] = [
          '@type' => 'HowToStep',
          '@id' => $context->canonicalUrl . '#howto-step' . $position,
          'position' => $position,
          'name' => $stepName,
          'text' => $text,
        ];
      }
    }

    if (count($steps) < self::MINIMUM_STEPS) {
      return [];
    }

    $howto = [
      '@type' => 'HowTo',
      '@id' => $context->canonicalUrl . '#howto',
    ];
    if ($howtoName !== '') {
      $howto['name'] = $howtoName;
    }
    $howto['step'] = $steps;

    return [$howto];
  }

  /**
   * Read and strip a step item's text field, or '' when empty/missing.
   */
  private function stepText(FieldableEntityInterface $item, string $field): string {
    if (!$item->hasField($field) || $item->get($field)->isEmpty()) {
      return '';
    }
    return $this->plainText((string) $item->get($field)->value);
  }

}
