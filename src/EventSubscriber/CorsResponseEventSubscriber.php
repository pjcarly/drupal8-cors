<?php

/**
 * @file
 * Contains Drupal\cors\EventSubscriber\CorsResponseEventSubscriber
 */

namespace Drupal\cors\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Response Event Subscriber for adding CORS headers.
 */
class CorsResponseEventSubscriber implements EventSubscriberInterface
{
  /**
   * The CORS config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The path alias manager.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The path matcher.
   *
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  protected $pathMatcher;

  /**
   * Constructs a new CORS response event subscriber.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Path\AliasManagerInterface $alias_manager
   *   The alias manager.
   * @param \Drupal\Core\Path\PathMatcherInterface $path_matcher
   *   The path matcher.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AliasManagerInterface $alias_manager, PathMatcherInterface $path_matcher)
  {
    $this->config = $config_factory->get('cors.settings');
    $this->aliasManager = $alias_manager;
    $this->pathMatcher = $path_matcher;
  }

  /**
   * Adds CORS headers to the response.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The GET response event.
   */
  public function addCorsHeaders(FilterResponseEvent $event)
  {
    /** @var array $domains */
    $domains = $this->config->get('domains');
    $request = $event->getRequest();
    $path_info = $request->getPathInfo();
    $current_path = $this->aliasManager->getPathByAlias($path_info);
    $request_headers = $request->headers->all();
    $headers_per_path = [];
    foreach ($domains as $path => $settings)
    {
      $settings = explode('|', $settings);
      $page_match = $this->pathMatcher->matchPath($current_path, $path);
      if ($current_path !== $path_info)
      {
        $page_match = $page_match || $this->pathMatcher->matchPath($path_info, $path);
      }
      if ($page_match)
      {
        if (!empty($settings[0]))
        {
          $origins = array_map('trim', explode(',', $settings[0]));

          // First we check if the request has an Origin
          if(array_key_exists('origin', $request_headers) && is_array($request_headers['origin']) && sizeof($request_headers['origin']) > 0)
          {
            // Now we know that an origin is present, lets check if it is an allowed origin
            // either the origin is defined as an allowed origin, or <mirror> is defined, which mirrors the request origin basically allowing all.
            if(in_array($request_headers['origin'][0], $origins) || in_array('<mirror>', $origins))
            {
              $headers_per_path[$path]['Access-Control-Allow-Origin'] = $request_headers['origin'][0];
            }
          }
          else if(is_array($origins) && sizeof($origins) > 0)
          {
            $headers_per_path[$path]['Access-Control-Allow-Origin'] = $origins[0];
          }

          // if we didnt find an origin. lets set one
          if(!array_key_exists($path, $headers_per_path) || !array_key_exists('Access-Control-Allow-Origin', $headers_per_path[$path]))
          {
            if(is_array($origins) && sizeof($origins) > 0)
            {
              $headers_per_path[$path]['Access-Control-Allow-Origin'] = $origins[0];
            }
            else
            {
              $headers_per_path[$path]['Access-Control-Allow-Origin'] = null;
            }
          }
        }
        if (!empty($settings[1]))
        {
          $headers_per_path[$path]['Access-Control-Allow-Methods'] = $this->formatMultipleValueHeader($settings[1]);
        }
        if (!empty($settings[2]))
        {
          $headers_per_path[$path]['Access-Control-Allow-Headers'] = $this->formatMultipleValueHeader($settings[2]);
        }
        if (!empty($settings[3]))
        {
          $headers_per_path[$path]['Access-Control-Allow-Credentials'] = trim($settings[3]);
        }
      }
    }

    $response = $event->getResponse();

    /** @var array $headers */
    foreach ($headers_per_path as $path => $headers)
    {
      foreach ($headers as $header => $values)
      {
        if (!empty($values))
        {
          $response->headers->set($header, $values, TRUE);
        }
      }
    }
  }

  /**
   * Helper function to format headers that might have multiple values.
   *
   * @param string $value
   *   CORS settings value.
   *
   * @return string
   *   Formatted value.
   */
  protected function formatMultipleValueHeader($value)
  {
    return implode(', ', array_map('trim', explode(',', $value)));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents()
  {
    $events[KernelEvents::RESPONSE][] = array('addCorsHeaders');
    return $events;
  }

}
