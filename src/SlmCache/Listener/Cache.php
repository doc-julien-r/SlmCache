<?php
/**
 * Copyright (c) 2013 Jurian Sluiman.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the names of the copyright holders nor the names of the
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author      Jurian Sluiman <jurian@juriansluiman.nl>
 * @copyright   2013 Jurian Sluiman.
 * @license     http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @link        http://juriansluiman.nl
 */

namespace SlmCache\Listener;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\Mvc\Router\RouteMatch;
use Laminas\Http\Response;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Cache\StorageFactory;
use Laminas\Cache\Storage\StorageInterface;

class Cache extends AbstractListenerAggregate
{
    protected $cache_prefix = 'slm_cache_';

    protected $match;
    protected $serviceLocator;

    public function __construct(ServiceLocatorInterface $sl)
    {
        $this->serviceLocator = $sl;

        $config = $sl->get('Config');

        if (isset($config['slm_cache']['cache_prefix'])) {
            $this->cache_prefix = $config['slm_cache']['cache_prefix'];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = null)
    {
        $events->attach(MvcEvent::EVENT_ROUTE, array($this, 'matchRoute'));
        $events->attach(MvcEvent::EVENT_FINISH, array($this, 'saveRoute'));
    }

    public function matchRoute(MvcEvent $e)
    {
        $match = $this->match($e);

        if (null === $match) {
            return;
        }

        $result = $this->fromCache($e, $match);

        if ($result instanceof Response) {
            return $result;
        }
    }

    public function saveRoute(MvcEvent $e)
    {
        // At EVENT_ROUTE the route did not match
        if (null === $this->match) {
            return;
        }

        // Page just fetched from cache, no need to store
        if (true === $e->getParam('cached')) {
            return;
        }

        $this->storeCache($e, $this->match);
    }

    protected function match(MvcEvent $e)
    {
        $match = $e->getRouteMatch();
        if (!$match instanceof RouteMatch) {
            return;
        }

        $route  = $match->getMatchedRouteName();
        $routeParameters = $match->getParams();
        $config = $this->serviceLocator->get('Config');
        $routes = $config['slm_cache']['routes'];

        if (!array_key_exists($route, $routes)) {
            return;
        }
        $config = (array) $routes[$route];

        // Match HTTP request method to configured methods
        if (array_key_exists('match_method', $config)) {
            $methods = (array) $config['match_method'];
            $method  = $e->getRequest()->getMethod();

            if (!in_array($method, $methods)) {
                return;
            }
        }

        // Match route request parameters to configured parameters
        if (array_key_exists('match_route_params', $config)) {
            $params = (array) $config['match_route_params'];

            foreach ($params as $name => $value) {
                // There is a specific route parameter
                if (is_string($value) && $value !== $match->getParam($name)) {
                    return;
                }

                // There are multiple values possible
                if (is_array($value) && !in_array($match->getParam($name), $value)) {
                    return;
                }
            }
        }

        $match  = ['route' => $route, 'config' => $config, 'parameters' => $routeParameters];
        $this->match = $match;

        return $match;
    }

    protected function fromCache(MvcEvent $e, $match)
    {
        $key    = $this->getCacheKey($match);
        $cache  = $this->getCache($e);

        $response = $e->getResponse();

        if (($result = $cache->getItem($key))) {
            $response->setContent(
                empty($this->serviceLocator->get('Config')['slm_cache']['use_compression'])
                ? $result
                : gzuncompress($result)
            );
            $response->getHeaders()->addHeaderLine('X-Slm-Cache', 'Fetch: Hit; route=' . $match['route']);
            $e->setParam('cached', true);

            return $response;
        }

        $response->getHeaders()->addHeaderLine('X-Slm-Cache', 'Fetch: Miss; route=' . $match['route']);
    }

    protected function storeCache(MvcEvent $e, $match)
    {
        $key    = $this->getCacheKey($match);
        $cache  = $this->getCache($e);

        $response = $e->getResponse();
        $response->getHeaders()->addHeaderLine('X-Slm-Cache', 'Storage: Success; route=' . $match['route']);

        if (!empty($this->serviceLocator->get('Config')['slm_cache']['use_compression'])) {
            return $cache->setItem($key, gzcompress($response->getContent()));
        }

        $cache->setItem($key, $response->getContent());
    }

    protected function getCache(MvcEvent $e)
    {
        $config = $this->serviceLocator->get('Config')['slm_cache']['cache'];

        if (is_string($config)) {
            $cache = $this->serviceLocator->get($config);
        } elseif (is_array($config)) {
            $cache = StorageFactory::factory($config);
        } else {
            throw new \Exception('Cache must be configured');
        }

        if (!$cache instanceof StorageInterface) {
            throw new \Exception('Cache is no instance of storage interface!');
        }

        return $cache;
    }

    /**
     * @param array $match Associative array corresponding to the matched route
     * @return string
     */
    protected function getCacheKey(array $match)
    {
        return $this->cache_prefix.sha1(serialize($match));
    }
}
