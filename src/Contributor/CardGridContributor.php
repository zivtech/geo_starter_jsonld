<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld\Contributor;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\geo_starter_jsonld\JsonLdContext;
use Drupal\geo_starter_jsonld\JsonLdFieldTrait;
use Drupal\node\NodeInterface;

/**
 * Emits a schema.org ItemList from section_card_grid paragraphs.
 *
 * Each card that references a published node becomes a ListItem (jsonld plan
 * §3). Unpublished targets are dropped; a grid with no published targets emits
 * nothing. One ItemList per section_card_grid instance. The referenced node's
 * cache tag is bubbled so unpublishing a card recomputes the list.
 */
final class CardGridContributor implements ParagraphContributorInterface {

  use JsonLdFieldTrait;

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node, EntityViewDisplayInterface $display): bool {
    return $node->hasField('field_sections') && $this->isVisible($display, 'field_sections');
  }

  /**
   * {@inheritdoc}
   */
  public function contribute(NodeInterface $node, EntityViewDisplayInterface $display, JsonLdContext $context): array {
    if ($node->get('field_sections')->isEmpty()) {
      return [];
    }

    $lists = [];

    foreach ($node->get('field_sections')->referencedEntities() as $section) {
      if ($section->bundle() !== 'section_card_grid') {
        continue;
      }
      $context->cacheability->addCacheableDependency($section);
      if (!$section->hasField('field_section_cards') || $section->get('field_section_cards')->isEmpty()) {
        continue;
      }

      $items = [];
      $position = 0;
      foreach ($section->get('field_section_cards')->referencedEntities() as $card) {
        // Bubble the tag before the published check so an unpublish recomputes.
        $context->cacheability->addCacheableDependency($card);
        if (!$card instanceof NodeInterface || !$card->isPublished()) {
          continue;
        }
        $position++;
        $items[] = [
          '@type' => 'ListItem',
          'position' => $position,
          'url' => $this->absoluteUrl($card, $context),
          'name' => $card->label(),
        ];
      }

      if ($items === []) {
        continue;
      }
      $lists[] = [
        '@type' => 'ItemList',
        '@id' => $context->canonicalUrl . '#cards' . (count($lists) + 1),
        'itemListElement' => $items,
      ];
    }

    return $lists;
  }

}
