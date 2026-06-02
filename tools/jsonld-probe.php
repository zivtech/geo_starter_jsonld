<?php

/**
 * @file
 * Repeatable acceptance probe for geo_starter_jsonld.
 *
 * Mirrors the recipe's JSON:API 200/403 probe discipline. Run against a site
 * installed from the geo_starter recipe (the emergency assistance Service node
 * must carry a 2-pair section_faq and >=1 published evidence source):
 *
 *   ddev drush scr web/modules/custom/geo_starter_jsonld/tools/jsonld-probe.php
 *
 * Exits non-zero if any assertion fails. NOT a substitute for the PHPUnit
 * Kernel/Functional suite (geo_starter_jsonld plan Task 7) — a fast smoke
 * probe.
 */

declare(strict_types=1);

use Drupal\Core\Entity\Entity\EntityViewDisplay;

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
  printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
  $ok ? $pass++ : $fail++;
};

$service_uuid = '41000000-0000-4000-8000-000000000001';
$nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $service_uuid]);
$node = reset($nodes);
if (!$node) {
  print "FAIL: sample Service node $service_uuid not found — is the recipe installed?\n";
  exit(1);
}

// The resolved render display for the full view (what
// hook_node_view_alter() receives).
$display = EntityViewDisplay::collectRenderDisplay($node, 'full');
$builder = \Drupal::service('geo_starter_jsonld.graph_builder');

$result = $builder->build($node, $display);
$check('builder emits JSON-LD for the published Service', $result !== NULL);
$document = json_decode($result['json'] ?? 'null', TRUE);
$check('payload is valid JSON', is_array($document) && isset($document['@graph']));

$graph = $document['@graph'] ?? [];
$by_type = static fn (string $type): array => array_values(array_filter($graph, static fn ($o) => ($o['@type'] ?? '') === $type));
$service = $by_type('Service')[0] ?? NULL;
$webpage = $by_type('WebPage')[0] ?? NULL;
$faqpage = $by_type('FAQPage')[0] ?? NULL;

$check('graph contains a WebPage', $webpage !== NULL);
$check('graph contains a Service', $service !== NULL);
$check('WebPage.mainEntity links the Service @id', ($webpage['mainEntity']['@id'] ?? NULL) === ($service['@id'] ?? '#'));
$check('FAQPage present with mainEntity length == 2 (marquee)', $faqpage !== NULL && count($faqpage['mainEntity'] ?? []) === 2);
$howto = $by_type('HowTo')[0] ?? NULL;
$check('HowTo present with 2 steps (from section_step_list)', $howto !== NULL && count($howto['step'] ?? []) === 2);
$check('HowTo has a name (from section_step_list heading)', !empty($howto['name'] ?? NULL));
$item_list = $by_type('ItemList')[0] ?? NULL;
$check('ItemList present with >=1 ListItem (from section_card_grid)', $item_list !== NULL && count($item_list['itemListElement'] ?? []) >= 1);

// Contact data nests under the provider Organization (schema.org domain), not
// the Service or the ContactPoint.
$provider = $service['provider'] ?? [];
$check('Service.provider nests a ContactPoint (from section_contact_panel)', ($provider['contactPoint']['@type'] ?? NULL) === 'ContactPoint' && !empty($provider['contactPoint']['telephone']));
$check('Service.provider carries a PostalAddress', ($provider['address']['@type'] ?? NULL) === 'PostalAddress');
// Structured hours nest under the ContactPoint as hoursAvailable
// (OpeningHoursSpecification); the sample ships Mon-Fri 09:00-17:00.
$hours = $provider['contactPoint']['hoursAvailable'] ?? NULL;
$check(
  'contactPoint.hoursAvailable carries 5 OpeningHoursSpecification (Mon-Fri)',
  is_array($hours) && count($hours) === 5
    && ($hours[0]['@type'] ?? NULL) === 'OpeningHoursSpecification',
);
$check(
  'first hoursAvailable is Monday 09:00-17:00',
  ($hours[0]['dayOfWeek'] ?? NULL) === 'https://schema.org/Monday'
    && ($hours[0]['opens'] ?? NULL) === '09:00'
    && ($hours[0]['closes'] ?? NULL) === '17:00',
);
$check('Service omits hoursAvailable when the ContactPoint carries it', !isset($service['hoursAvailable']));

// Domain-correctness guards: page-level CreativeWork metadata lives on the
// WebPage (a CreativeWork), never on the non-CreativeWork Service. These lock
// the 2026-06-02 relocation against regression at the full-surface level.
$check('WebPage has >=1 citation (moved off Service)', !empty($webpage['citation']));
$check('WebPage carries reviewedBy (WebPage-domain-only)', ($webpage['reviewedBy']['@type'] ?? NULL) === 'Person');
$check(
  'Service carries none of reviewedBy/citation/about/dateModified (moved to WebPage)',
  !isset($service['reviewedBy']) && !isset($service['citation']) && !isset($service['about']) && !isset($service['dateModified']),
);

// Every citation @id must resolve to a CreativeWork at that @id on its own
// page.
$resolved = TRUE;
foreach ($webpage['citation'] ?? [] as $citation) {
  $id = $citation['@id'] ?? '';
  $found = FALSE;
  foreach (\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'evidence_source']) as $evidence) {
    if (!$evidence->isPublished()) {
      continue;
    }
    $ev_display = EntityViewDisplay::collectRenderDisplay($evidence, 'full');
    $ev_graph = json_decode($builder->build($evidence, $ev_display)['json'] ?? 'null', TRUE)['@graph'] ?? [];
    foreach ($ev_graph as $object) {
      if (($object['@id'] ?? '') === $id && ($object['@type'] ?? '') === 'CreativeWork') {
        $found = TRUE;
      }
    }
  }
  $resolved = $resolved && $found;
}
$check('every citation @id resolves to a CreativeWork (DoD 3b)', $resolved);

// Published guard: an unpublished node emits nothing.
$draft = clone $node;
$draft->setUnpublished();
$check('published guard: draft emits no JSON-LD', $builder->build($draft, $display) === NULL);

// Citation suppression: in-memory unpublish drops the @id but keeps the
// cache tag.
$refs = $node->get('field_evidence_sources')->referencedEntities();
if ($refs) {
  $suppressed = $refs[0];
  $tag = 'node:' . $suppressed->id();
  $url = $suppressed->toUrl()->toString();
  $suppressed->setUnpublished();
  $after = $builder->build($node, $display);
  // Citations live on the WebPage now, so read the suppression result there.
  $after_webpage = (static function (array $g) {
    foreach ($g as $o) {
      if (($o['@type'] ?? '') === 'WebPage') {
        return $o;
      }
    }
    return [];
  })(json_decode($after['json'], TRUE)['@graph']);
  $ids = array_column($after_webpage['citation'] ?? [], '@id');
  $dropped = TRUE;
  foreach ($ids as $id) {
    if (str_starts_with($id, $url . '#')) {
      $dropped = FALSE;
    }
  }
  $check('suppression: unpublished evidence @id dropped', $dropped);
  $check('suppression: cross-entity cache tag still bubbled', in_array($tag, $after['cacheability']->getCacheTags(), TRUE));
}

// Answer + Article normalizers (Phase A, independent of the paragraph library).
$build_first = static function (string $type) use ($builder): ?array {
  foreach (\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => $type]) as $candidate) {
    if (!$candidate->isPublished()) {
      continue;
    }
    $result = $builder->build($candidate, EntityViewDisplay::collectRenderDisplay($candidate, 'full'));
    if ($result !== NULL) {
      return json_decode($result['json'], TRUE)['@graph'];
    }
  }
  return NULL;
};
$first_of_type = static function (array $graph, string $schema_type): ?array {
  foreach ($graph as $object) {
    if (($object['@type'] ?? '') === $schema_type) {
      return $object;
    }
  }
  return NULL;
};

$answer = $first_of_type($build_first('answer') ?? [], 'Question');
$check(
  'Answer emits a Question (#answer) with acceptedAnswer.text',
  $answer !== NULL
    && str_ends_with($answer['@id'] ?? '', '#answer')
    && !empty($answer['acceptedAnswer']['text']),
);

$article = $first_of_type($build_first('article') ?? [], 'Article');
$check(
  'Article emits an Article (#article) with headline + datePublished',
  $article !== NULL
    && str_ends_with($article['@id'] ?? '', '#article')
    && !empty($article['headline'])
    && !empty($article['datePublished']),
);

printf("\n%d passed, %d failed\n", $pass, $fail);
if ($fail > 0) {
  exit(1);
}
