<?php

/*
 * This file is part of the NelmioApiDocBundle package.
 *
 * (c) Nelmio
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\ApiDocBundle\Describer;

use Doctrine\Common\Annotations\Reader;
use Nelmio\ApiDocBundle\Annotation\Operation;
use Nelmio\ApiDocBundle\Annotation\Security;
use Nelmio\ApiDocBundle\OpenApiPhp\Util;
use Nelmio\ApiDocBundle\Util\ControllerReflector;
use OpenApi\Analyser;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

// Help opcache.preload discover Swagger\Annotations\Swagger
class_exists(OA\OpenApi::class);

final class OpenApiPhpDescriber
{
    private $routeCollection;
    private $controllerReflector;
    private $annotationReader;
    private $logger;
    private $overwrite;

    public function __construct(RouteCollection $routeCollection, ControllerReflector $controllerReflector, Reader $annotationReader, LoggerInterface $logger, bool $overwrite = false)
    {
        $this->routeCollection = $routeCollection;
        $this->controllerReflector = $controllerReflector;
        $this->annotationReader = $annotationReader;
        $this->logger = $logger;
        $this->overwrite = $overwrite;
    }

    public function describe(OA\OpenApi $api)
    {
        $classAnnotations = [];

        /** @var \ReflectionMethod $method */
        foreach ($this->getMethodsToParse() as $method => list($path, $httpMethods, $routeName)) {
            $declaringClass = $method->getDeclaringClass();

            $path = Util::getPath($api, $path);

            Analyser::$context = Util::createContext(['nested' => $path], $path->_context);
            Analyser::$context->namespace = $method->getNamespaceName();
            Analyser::$context->class = $declaringClass->getShortName();
            Analyser::$context->method = $method->name;
            Analyser::$context->filename = $method->getFileName();

            if (!array_key_exists($declaringClass->getName(), $classAnnotations)) {
                $classAnnotations = array_filter($this->annotationReader->getClassAnnotations($declaringClass), function ($v) {
                    return $v instanceof OA\AbstractAnnotation;
                });
                $classAnnotations[$declaringClass->getName()] = $classAnnotations;
            }

            $annotations = array_filter($this->annotationReader->getMethodAnnotations($method), function ($v) {
                return $v instanceof OA\AbstractAnnotation;
            });

            if (0 === count($annotations) && 0 === count($classAnnotations[$declaringClass->getName()])) {
                continue;
            }

            $implicitAnnotations = [];
            $mergeProperties = new \stdClass();

            foreach (array_merge($annotations, $classAnnotations[$declaringClass->getName()]) as $annotation) {
                if ($annotation instanceof Operation) {
                    foreach ($httpMethods as $httpMethod) {
                        $operation = Util::getOperation($path, $httpMethod);
                        $operation->mergeProperties($annotation);
                    }

                    continue;
                }

                if ($annotation instanceof OA\Operation) {
                    if (!in_array($annotation->method, $httpMethods, true)) {
                        continue;
                    }
                    if (OA\UNDEFINED !== $annotation->path && $path->path !== $annotation->path) {
                        continue;
                    }

                    $operation = Util::getOperation($path, $annotation->method);
                    $operation->mergeProperties($annotation);

                    continue;
                }

                if ($annotation instanceof Security) {
                    $annotation->validate();
                    $mergeProperties->security[] = [$annotation->name => $annotation->scopes];

                    continue;
                }

                if ($annotation instanceof OA\Tag) {
                    $annotation->validate();
                    $mergeProperties->tags[] = $annotation->name;

                    continue;
                }

                if (
                    !$annotation instanceof OA\Response &&
                    !$annotation instanceof OA\RequestBody &&
                    !$annotation instanceof OA\Parameter &&
                    !$annotation instanceof OA\ExternalDocumentation
                ) {
                    throw new \LogicException(sprintf('Using the annotation "%s" as a root annotation in "%s::%s()" is not allowed.', get_class($annotation), $method->getDeclaringClass()->name, $method->name));
                }

                $implicitAnnotations[] = $annotation;
            }

            if (empty($implicitAnnotations) && empty(get_object_vars($mergeProperties))) {
                continue;
            }

            foreach ($httpMethods as $httpMethod) {
                $operation = Util::getOperation($path, $httpMethod);
                $operation->merge($implicitAnnotations);
                $operation->mergeProperties($mergeProperties);

                if (OA\UNDEFINED === $operation->operationId) {
                    $operation->operationId = $httpMethod.'_'.$routeName;
                }
            }
        }

        // Reset the Analyser after the parsing
        Analyser::$context = null;
    }

    private function getMethodsToParse(): \Generator
    {
        foreach ($this->routeCollection->all() as $routeName => $route) {
            if (!$route->hasDefault('_controller')) {
                continue;
            }
            $controller = $route->getDefault('_controller');
            $reflectedMethod = $this->controllerReflector->getReflectionMethod($controller);
            if (null === $reflectedMethod) {
                continue;
            }
            $path = $this->normalizePath($route->getPath());
            $supportedHttpMethods = $this->getSupportedHttpMethods($route);
            if (empty($supportedHttpMethods)) {
                $this->logger->warning('None of the HTTP methods specified for path {path} are supported by swagger-ui, skipping this path', [
                    'path' => $path,
                ]);

                continue;
            }
            yield $reflectedMethod => [$path, $supportedHttpMethods, $routeName];
        }
    }

    private function getSupportedHttpMethods(Route $route): array
    {
        $allMethods = Util::OPERATIONS;
        $methods = array_map('strtolower', $route->getMethods());

        return array_intersect($methods ?: $allMethods, $allMethods);
    }

    private function normalizePath(string $path): string
    {
        if ('.{_format}' === substr($path, -10)) {
            $path = substr($path, 0, -10);
        }

        return $path;
    }
}
