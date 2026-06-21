<?php
/**
 * FastRouteSimple - Simple FastRoute fallback for systems without Composer
 * This is a minimal implementation; production should use actual FastRoute library
 */

namespace FastRoute;

class RouteCollector {
    private $routes = [];

    public function addRoute($method, $route, $handler) {
        $this->routes[] = [
            'method' => $method,
            'route' => $route,
            'handler' => $handler
        ];
    }

    public function getData() {
        return $this->routes;
    }
}

class Dispatcher {
    public static function simpleDispatcher($callback) {
        $collector = new RouteCollector();
        call_user_func($callback, $collector);
        return $collector->getData();
    }
}
?>
