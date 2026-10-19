<?php

declare(strict_types=1);

namespace Mailadmin\Http\Routing;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Merges the per-package contract files into one document (U-3).
 *
 * The contract is one logical document that lives in several files, so that
 * eight packages can extend it without queueing behind each other. This class
 * turns those files back into the single document every consumer wants:
 *
 *   openapi/openapi.yaml        root — info, servers, security; owned by F2
 *   openapi/paths/*.yaml        one file per endpoint group
 *   openapi/components/*.yaml   one file per package that needs own schemas
 *
 * Three properties are deliberate:
 *
 *  - **A collision is an error, never a silent overwrite.** Two files that
 *    define the same path, the same component name or the same tag stop the
 *    build and the message names both files. Last-one-wins would make a
 *    rename in one package quietly delete another package's endpoint.
 *  - **The output is deterministic.** Files are read in sorted order and every
 *    map is sorted by key, so the same sources always produce the same bytes —
 *    which is what makes the SHA-256 in U-8 a meaningful drift check rather
 *    than noise.
 *  - **File-relative `$ref`s are rewritten to internal pointers**, and every
 *    pointer is resolved against the merged document. A reference to something
 *    that does not exist is caught here, not by a `500` in production.
 */
final readonly class SpecificationBundler
{
    public const string ROOT_FILE = 'openapi.yaml';

    /** Only these three keys may appear in a partial. Everything else belongs in the root. */
    private const array PARTIAL_KEYS = ['paths', 'components', 'tags'];

    public function __construct(private string $sourceDirectory)
    {
    }

    public function sourceDirectory(): string
    {
        return $this->sourceDirectory;
    }

    /**
     * Every file the bundle is built from, root first, then partials in sorted
     * order.
     *
     * @return list<string> absolute paths
     */
    public function sourceFiles(): array
    {
        $root = $this->sourceDirectory . '/' . self::ROOT_FILE;

        if (!is_file($root)) {
            throw ContractBuildError::because(sprintf('The contract root %s does not exist.', $root));
        }

        return [$root, ...$this->partialFiles()];
    }

    /**
     * A fingerprint over the *sources*, used in development to notice that the
     * bundle is older than the files it was built from (U-8).
     *
     * Content, not mtime: a checkout or a file copy rewrites mtimes without
     * changing anything, and rebuilding for that would be noise.
     */
    public function sourceFingerprint(): string
    {
        $parts = [];

        foreach ($this->sourceFiles() as $file) {
            $hash = hash_file('sha256', $file);

            if ($hash === false) {
                throw ContractBuildError::because(sprintf('Contract file %s could not be read.', $file));
            }

            $parts[] = $this->relative($file) . ':' . $hash;
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @return array<string, mixed> the merged document
     *
     * @throws ContractBuildError
     */
    public function bundle(): array
    {
        [$root, $files] = [$this->sourceFiles()[0], array_slice($this->sourceFiles(), 1)];
        $document = $this->read($root);

        foreach (self::PARTIAL_KEYS as $key) {
            /** @var mixed $existing */
            $existing = $document[$key] ?? [];

            if (!is_array($existing)) {
                throw ContractBuildError::because(sprintf(
                    'The contract root declares "%s" as something other than a mapping.',
                    $key,
                ));
            }

            $document[$key] = $existing;
        }

        /** @var array<string, mixed> $paths */
        $paths = $document['paths'];
        /** @var array<string, mixed> $components */
        $components = $document['components'];
        /** @var array<int, mixed> $tags */
        $tags = array_values($document['tags']);

        // Who contributed what, so that a collision message can name both sides.
        /** @var array<string, string> $pathOwner */
        $pathOwner = [];
        /** @var array<string, string> $componentOwner */
        $componentOwner = [];
        /** @var array<string, string> $tagOwner */
        $tagOwner = [];

        foreach ($files as $file) {
            $partial = $this->read($file);
            $origin = $this->relative($file);

            foreach (array_keys($partial) as $key) {
                if (!in_array($key, self::PARTIAL_KEYS, true)) {
                    throw ContractBuildError::because(sprintf(
                        '%s declares the top-level key "%s". A partial may only contribute %s; '
                        . 'everything else belongs in %s.',
                        $origin,
                        $key,
                        implode(', ', self::PARTIAL_KEYS),
                        self::ROOT_FILE,
                    ));
                }
            }

            $paths = $this->mergePaths($paths, $partial, $origin, $pathOwner);
            $components = $this->mergeComponents($components, $partial, $origin, $componentOwner);
            $tags = $this->mergeTags($tags, $partial, $origin, $tagOwner);
        }

        ksort($paths);
        $document['paths'] = $paths;
        $document['components'] = $this->sortComponents($components);

        usort($tags, static function (mixed $left, mixed $right): int {
            $leftName = is_array($left) && is_string($left['name'] ?? null) ? $left['name'] : '';
            $rightName = is_array($right) && is_string($right['name'] ?? null) ? $right['name'] : '';

            return strcmp($leftName, $rightName);
        });

        if ($tags === []) {
            unset($document['tags']);
        } else {
            $document['tags'] = $tags;
        }

        /** @var array<string, mixed> $resolved */
        $resolved = $this->rewriteReferences($document, $document, '');

        return $resolved;
    }

    /**
     * Serialises the bundle. **The single implementation**, because the SHA-256
     * that guards against a stale build (U-8) is taken over these exact bytes —
     * two callers dumping with slightly different flags would produce a
     * permanent false mismatch.
     *
     * Two flags earn their place:
     *
     *  - `DUMP_EMPTY_ARRAY_AS_SEQUENCE`, because every empty collection in an
     *    OpenAPI document is a list: `security: []` on a public operation, and
     *    the empty scope list of a security requirement. Written as `{}` they
     *    would read back as maps and no longer validate.
     *  - Numeric keys are quoted afterwards. `responses: {200: …}` is a map of
     *    *strings* in OpenAPI, but PHP turns "200" into an integer key and the
     *    dumper then writes it bare, which YAML reads back as a number. Quoting
     *    a key never changes what a map means, so the rewrite is safe wherever
     *    it applies.
     *
     * @param array<string, mixed> $document
     */
    public static function toYaml(array $document): string
    {
        $yaml = Yaml::dump($document, 512, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

        // Anchored to a whole line so that it can only ever hit a mapping key.
        // Scalars are dumped inline (`description: "…"`), never as bare digits
        // on a line of their own.
        return (string) preg_replace('/^(\s*)(\d+):$/m', "$1'$2':", $yaml);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws \JsonException
     */
    public static function toJson(array $document): string
    {
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array<string, mixed> $paths
     * @param array<string, mixed> $partial
     * @param array<string, string> $owner
     *
     * @return array<string, mixed>
     */
    private function mergePaths(array $paths, array $partial, string $origin, array &$owner): array
    {
        /** @var mixed $contributed */
        $contributed = $partial['paths'] ?? [];

        if (!is_array($contributed)) {
            throw ContractBuildError::because(sprintf('%s declares "paths" as something other than a mapping.', $origin));
        }

        /** @var mixed $item */
        foreach ($contributed as $path => $item) {
            if (!is_string($path) || !str_starts_with($path, '/')) {
                throw ContractBuildError::because(sprintf(
                    '%s declares the path "%s". A path must be a string beginning with "/".',
                    $origin,
                    is_scalar($path) ? (string) $path : gettype($path),
                ));
            }

            if (isset($paths[$path])) {
                throw ContractBuildError::because(sprintf(
                    'Path "%s" is declared twice: in %s and in %s. A path belongs to exactly one file, '
                    . 'so that ownership is unambiguous — split the path if two packages need it.',
                    $path,
                    $owner[$path] ?? self::ROOT_FILE,
                    $origin,
                ));
            }

            $paths[$path] = $item;
            $owner[$path] = $origin;
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $partial
     * @param array<string, string> $owner
     *
     * @return array<string, mixed>
     */
    private function mergeComponents(array $components, array $partial, string $origin, array &$owner): array
    {
        /** @var mixed $contributed */
        $contributed = $partial['components'] ?? [];

        if (!is_array($contributed)) {
            throw ContractBuildError::because(sprintf(
                '%s declares "components" as something other than a mapping.',
                $origin,
            ));
        }

        /** @var mixed $entries */
        foreach ($contributed as $section => $entries) {
            if (!is_string($section) || !is_array($entries)) {
                throw ContractBuildError::because(sprintf(
                    '%s declares a components section that is not a mapping of names.',
                    $origin,
                ));
            }

            /** @var array<string, mixed> $existing */
            $existing = is_array($components[$section] ?? null) ? $components[$section] : [];

            /** @var mixed $definition */
            foreach ($entries as $name => $definition) {
                if (!is_string($name)) {
                    throw ContractBuildError::because(sprintf(
                        '%s declares a non-string component name in "%s".',
                        $origin,
                        $section,
                    ));
                }

                $key = $section . '/' . $name;

                if (isset($existing[$name])) {
                    throw ContractBuildError::because(sprintf(
                        'Component "#/components/%s" is declared twice: in %s and in %s. '
                        . 'Reuse the existing one or give yours a package-specific name.',
                        $key,
                        $owner[$key] ?? self::ROOT_FILE,
                        $origin,
                    ));
                }

                $existing[$name] = $definition;
                $owner[$key] = $origin;
            }

            $components[$section] = $existing;
        }

        return $components;
    }

    /**
     * @param array<int, mixed> $tags
     * @param array<string, mixed> $partial
     * @param array<string, string> $owner
     *
     * @return array<int, mixed>
     */
    private function mergeTags(array $tags, array $partial, string $origin, array &$owner): array
    {
        /** @var mixed $contributed */
        $contributed = $partial['tags'] ?? [];

        if (!is_array($contributed)) {
            throw ContractBuildError::because(sprintf('%s declares "tags" as something other than a list.', $origin));
        }

        /** @var mixed $tag */
        foreach ($contributed as $tag) {
            if (!is_array($tag) || !is_string($tag['name'] ?? null)) {
                throw ContractBuildError::because(sprintf(
                    '%s declares a tag without a string "name".',
                    $origin,
                ));
            }

            /** @var string $name */
            $name = $tag['name'];

            if (isset($owner[$name])) {
                throw ContractBuildError::because(sprintf(
                    'Tag "%s" is declared twice: in %s and in %s. The file that introduces a tag '
                    . 'declares it; every other file only refers to it.',
                    $name,
                    $owner[$name],
                    $origin,
                ));
            }

            $owner[$name] = $origin;
            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * @param array<string, mixed> $components
     *
     * @return array<string, mixed>
     */
    private function sortComponents(array $components): array
    {
        ksort($components);

        /** @var mixed $entries */
        foreach ($components as $section => $entries) {
            if (is_array($entries)) {
                ksort($entries);
                $components[$section] = $entries;
            }
        }

        return $components;
    }

    /**
     * Rewrites every file-relative `$ref` to an internal pointer and checks that
     * every pointer resolves against the merged document.
     *
     * @param array<array-key, mixed> $node
     * @param array<string, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function rewriteReferences(array $node, array $document, string $location): array
    {
        /** @var mixed $value */
        foreach ($node as $key => $value) {
            $here = $location . '/' . (is_string($key) ? $key : (string) $key);

            if ($key === '$ref') {
                if (!is_string($value)) {
                    throw ContractBuildError::because(sprintf('The $ref at %s is not a string.', $location));
                }

                $node[$key] = $this->internalPointer($value, $document, $location);

                continue;
            }

            if (is_array($value)) {
                $node[$key] = $this->rewriteReferences($value, $document, $here);
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function internalPointer(string $reference, array $document, string $location): string
    {
        $hash = strpos($reference, '#');

        if ($hash === false) {
            throw ContractBuildError::because(sprintf(
                'The reference "%s" at %s points at a whole file. The bundler merges files rather than '
                . 'inlining them, so a reference must name a component: "#/components/schemas/Thing".',
                $reference,
                $location === '' ? 'the document root' : $location,
            ));
        }

        $pointer = substr($reference, $hash + 1);

        if ($pointer === '' || !str_starts_with($pointer, '/')) {
            throw ContractBuildError::because(sprintf(
                'The reference "%s" at %s has no usable JSON pointer.',
                $reference,
                $location === '' ? 'the document root' : $location,
            ));
        }

        if (!$this->pointerResolves($pointer, $document)) {
            throw ContractBuildError::because(sprintf(
                'The reference "%s" at %s points at "#%s", which the bundled document does not contain. '
                . 'Either the component is missing or the file that defines it is not in openapi/components/.',
                $reference,
                $location === '' ? 'the document root' : $location,
                $pointer,
            ));
        }

        return '#' . $pointer;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function pointerResolves(string $pointer, array $document): bool
    {
        /** @var mixed $cursor */
        $cursor = $document;

        foreach (explode('/', ltrim($pointer, '/')) as $rawSegment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $rawSegment);

            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }

            /** @var mixed $cursor */
            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function partialFiles(): array
    {
        $files = [];

        foreach (['paths', 'components'] as $directory) {
            $found = glob($this->sourceDirectory . '/' . $directory . '/*.yaml');

            if ($found === false) {
                continue;
            }

            sort($found);
            $files = [...$files, ...$found];
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw ContractBuildError::because(sprintf('Contract file %s could not be read.', $file));
        }

        try {
            /** @var mixed $parsed */
            $parsed = Yaml::parse($contents);
        } catch (ParseException $exception) {
            throw ContractBuildError::because(sprintf(
                '%s is not valid YAML: %s',
                $this->relative($file),
                $exception->getMessage(),
            ));
        }

        if (!is_array($parsed)) {
            throw ContractBuildError::because(sprintf('%s does not contain a mapping.', $this->relative($file)));
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    private function relative(string $file): string
    {
        $prefix = $this->sourceDirectory . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
