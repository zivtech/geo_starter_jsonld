<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld_llms\Install\Requirements;

use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;

/**
 * Install time requirements for the geo_starter_jsonld_llms module.
 */
class GeoStarterJsonldLlmsRequirements implements InstallRequirementsInterface {

  /**
   * {@inheritdoc}
   */
  public static function getRequirements(): array {
    $requirements = [];
    if (\Drupal::moduleHandler()->moduleExists('llms_txt')) {
      $requirements['geo_starter_jsonld_llms_route_collision'] = [
        'title' => t('GEO Starter llms.txt'),
        'value' => t('Route collision with the llms_txt module'),
        'description' => t('Both geo_starter_jsonld_llms and llms_txt register the /llms.txt path. Drupal silently serves only one of them. Uninstall the llms_txt module first.'),
        'severity' => RequirementSeverity::Error,
      ];
    }
    return $requirements;
  }

}
