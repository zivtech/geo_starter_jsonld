<?php

declare(strict_types=1);

namespace Drupal\geo_starter_jsonld_llms\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\geo_starter_jsonld_llms\LlmsTxtBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves /llms.txt. Route concerns only; content lives in the builder.
 *
 * The response is a CacheableResponse carrying the builder's full cache
 * metadata, so anonymous crawler traffic is served from the page cache and
 * invalidates exactly when a listed node, bundle membership, or the site
 * identity/summary changes.
 */
final class LlmsTxtController implements ContainerInjectionInterface {

  /**
   * Constructs an LlmsTxtController.
   *
   * @param \Drupal\geo_starter_jsonld_llms\LlmsTxtBuilder $builder
   *   The llms.txt document builder.
   */
  public function __construct(
    private readonly LlmsTxtBuilder $builder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('geo_starter_jsonld_llms.builder'));
  }

  /**
   * Returns the llms.txt document.
   *
   * Content-Type is text/markdown (RFC 7763): the artifact IS markdown and
   * this is a machine-first endpoint. If a consumer ever chokes on it,
   * text/plain is a one-line revert with no other impact.
   */
  public function render(): CacheableResponse {
    $result = $this->builder->build();
    $response = new CacheableResponse($result['markdown'], 200, [
      'Content-Type' => 'text/markdown; charset=UTF-8',
    ]);
    $response->addCacheableDependency($result['cacheability']);
    return $response;
  }

}
