<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\RequireAuth;
use App\Middleware\RequireGuest;
use App\Middleware\RequirePermission;
use App\Middleware\RequireRole;
use App\Middleware\VerifyCsrf;

/**
 * Route table and dispatcher.
 *
 * Routes are declared in routes/web.php:
 *   $router->get('/residents/{id}', [Controller::class, 'show'], ['auth', 'role:Registrar']);
 *
 * Literal segments must be declared BEFORE wildcard routes that could also match.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:array<int,string>,handler:array,middleware:array<int,string>,name:?string}> */
    private array $routes = [];

    private static array $namedRoutes = [];

    /** Middleware aliases. 'role:Teacher' and 'can:enrollment.approve' take an argument. */
    private const MIDDLEWARE = [
        'auth'  => RequireAuth::class,
        'guest' => RequireGuest::class,
        'role'  => RequireRole::class,
        'can'   => RequirePermission::class,
        'csrf'  => VerifyCsrf::class,
    ];

    public function get(string $pattern, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $pattern, $handler, $middleware, $name);
    }

    public function post(string $pattern, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $pattern, $handler, $middleware, $name);
    }

    public function put(string $pattern, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('PUT', $pattern, $handler, $middleware, $name);
    }

    public function delete(string $pattern, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware, $name);
    }

    private function add(string $method, string $pattern, array $handler, array $middleware, ?string $name): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $pattern,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $middleware,
            'name'       => $name,
        ];

        if ($name !== null) {
            self::$namedRoutes[$name] = $pattern;
        }
    }

    /** Resolve a named route to its path, substituting parameters. */
    public static function route(string $name, array $params = []): string
    {
        $pattern = self::$namedRoutes[$name] ?? '/';
        foreach ($params as $key => $value) {
            $pattern = str_replace('{' . $key . '}', rawurlencode((string) $value), $pattern);
        }
        return $pattern;
    }

    public function dispatch(): void
    {
        Response::securityHeaders();

        $request = new Request();
        $method = $request->method();
        $path = $request->path();

        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;

            if ($route['method'] !== $method) {
                continue;
            }

            array_shift($matches);
            $request->setRouteParams(array_combine($route['params'], $matches) ?: []);

            $this->runMiddleware($route['middleware'], $request);

            [$class, $action] = $route['handler'];
            $controller = new $class();
            $controller->{$action}($request);
            return;
        }

        throw $pathMatched
            ? new HttpException(405, 'That action is not allowed on this page.')
            : HttpException::notFound();
    }

    private function runMiddleware(array $aliases, Request $request): void
    {
        // CSRF is verified on every state-changing request, whether or not the
        // route asked for it. Routes cannot opt out.
        if ($request->isStateChanging()) {
            (new VerifyCsrf())->handle($request);
        }

        foreach ($aliases as $alias) {
            [$name, $argument] = array_pad(explode(':', $alias, 2), 2, null);

            $class = self::MIDDLEWARE[$name] ?? null;
            if ($class === null) {
                throw new \RuntimeException("Unknown middleware alias: {$alias}");
            }
            if ($class === VerifyCsrf::class) {
                continue; // already applied above
            }

            (new $class())->handle($request, $argument);
        }
    }
}
