<?php

/**
 * @file
 * Repeatable acceptance probe for geo_starter_jsonld, mirroring the recipe's
 * JSON:API 200/403 probe discipline.
 *
 * Run against a site installed from the geo_starter recipe (the emergency
 * assistance Service node must carry a 2-pair section_faq and >=1 published
 * evidence source):
 *
 *   ddev drush scr web/modules/custom/geo_starter_jsonld/tools/jsonld-probe.php
 *
 * Exits non-zero if any assertion fails. NOT a substitute for the PHPUnit
 * Kernel/Functional suite (geo_starter_jsonld plan Task 7) — a fast smoke probe.
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

// The resolved render display for the full view (what hook_node_view_alter gets).
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
$check('Service has >=1 citation', !empty($service['citation']));

// Every citation @id must resolve to a CreativeWork at that @id on its own page.
$resolved = TRUE;
foreach ($service['citation'] ?? [] as $citation) {
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

// Citation suppression: in-memory unpublish drops the @id but keeps the cache tag.
$refs = $node->get('field_evidence_sources')->referencedEntities();
if ($refs) {
  $suppressed = $refs[0];
  $tag = 'node:' . $suppressed->id();
  $url = $suppressed->toUrl()->toString();
  $suppressed->setUnpublished();
  $after = $builder->build($node, $display);
  $after_service = (static function (array $g) {
    foreach ($g as $o) {
      if (($o['@type'] ?? '') === 'Service') {
        return $o;
      }
    }
    return [];
  })(json_decode($after['json'], TRUE)['@graph']);
  $ids = array_column($after_service['citation'] ?? [], '@id');
  $dropped = TRUE;
  foreach ($ids as $id) {
    if (str_starts_with($id, $url . '#')) {
      $dropped = FALSE;
    }
  }
  $check('suppression: unpublished evidence @id dropped', $dropped);
  $check('suppression: cross-entity cache tag still bubbled', in_array($tag, $after['cacheability']->getCacheTags(), TRUE));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
if ($fail > 0) {
  exit(1);
}
