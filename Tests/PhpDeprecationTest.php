<?php

namespace SunCat\MobileDetectBundle\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the bundle sources against the deprecation notices raised by PHP 8.0 to 8.5.
 *
 * Every check walks the bundle own sources (vendor excluded) so that a new class
 * reintroducing one of those patterns makes the test suite fail.
 */
class PhpDeprecationTest extends TestCase
{
    /**
     * Given a class assigning values on $this
     * When PHP 8.2 forbids the creation of undeclared properties
     * Then every assigned property must be declared by the class or one of its parents
     *
     * @test
     */
    public function noClassCreatesADynamicProperty()
    {
        $dynamicProperties = array();

        foreach ($this->bundleFiles() as $file => $source) {
            $className = $this->classNameOf($source);

            if (null === $className || !class_exists($className)) {
                continue;
            }

            $declared = array();
            foreach ((new \ReflectionClass($className))->getProperties() as $property) {
                $declared[$property->getName()] = true;
            }

            preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*=(?![=>])/', $source, $matches);

            foreach (array_unique($matches[1]) as $property) {
                if (!isset($declared[$property])) {
                    $dynamicProperties[] = sprintf('%s::$%s (%s)', $className, $property, $file);
                }
            }
        }

        $this->assertEquals(array(), $dynamicProperties);
    }

    /**
     * Given a parameter typed and defaulting to null
     * When PHP 8.4 deprecates the implicit nullable type
     * Then the type must be explicitly marked as nullable
     *
     * @test
     */
    public function noSignatureUsesAnImplicitNullableParameter()
    {
        $this->assertMatchesNothing(
            '/[(,]\s*(?!\?)(?!mixed\b)[A-Za-z_\\\\][A-Za-z0-9_\\\\|]*\s+(?:\.\.\.)?\$[A-Za-z0-9_]+\s*=\s*null\b/i',
            'implicit nullable parameter, the explicit "?" type must be used'
        );
    }

    /**
     * Given a signature mixing optional and required parameters
     * When PHP 8.0 deprecates an optional parameter declared before a required one
     * Then no default value may precede a parameter without default value
     *
     * @test
     */
    public function noSignatureDeclaresAnOptionalParameterBeforeARequiredOne()
    {
        $this->assertMatchesNothing(
            '/function\s+[A-Za-z_][A-Za-z0-9_]*\s*\([^)]*\$[A-Za-z0-9_]+\s*=\s*[^,)]+,[^)]*[\s(]\$[A-Za-z0-9_]+\s*\)/',
            'optional parameter declared before a required one'
        );
    }

    /**
     * Given http_build_query() whose second parameter is typed string
     * When PHP 8.1 deprecates passing null to a non nullable internal parameter
     * Then an empty string must be used as numeric prefix
     *
     * @test
     */
    public function noCallPassesNullAsHttpBuildQueryNumericPrefix()
    {
        $this->assertMatchesNothing(
            '/http_build_query\s*\((?:[^()]|\([^()]*\))*?,\s*null\s*[,)]/i',
            'null passed to http_build_query() $numeric_prefix'
        );
    }

    /**
     * Asserts that no bundle source matches the given deprecated pattern.
     *
     * @param string $pattern Regular expression describing the deprecated pattern
     * @param string $reason  Human readable explanation added to the failure message
     */
    private function assertMatchesNothing($pattern, $reason)
    {
        $violations = array();

        foreach ($this->bundleFiles() as $file => $source) {
            if (preg_match($pattern, $source)) {
                $violations[] = sprintf('%s: %s', $file, $reason);
            }
        }

        $this->assertEquals(array(), $violations);
    }

    /**
     * Returns the bundle own PHP sources indexed by relative path.
     *
     * @return array
     */
    private function bundleFiles()
    {
        $root = dirname(__DIR__);
        $files = array();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ('php' !== $file->getExtension() || false !== strpos($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $files[substr($path, strlen($root) + 1)] = file_get_contents($path);
        }

        ksort($files);

        return $files;
    }

    /**
     * Extracts the fully qualified name of the class declared by the given source.
     *
     * @param string $source
     *
     * @return string|null
     */
    private function classNameOf($source)
    {
        if (!preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $source, $namespace) ||
            !preg_match('/^\s*(?:(?:abstract|final)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $class)
        ) {
            return null;
        }

        return $namespace[1].'\\'.$class[1];
    }
}
