<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * One Mapsight placement. Sidecar reachability lives on {@see SsrClient}.
 *
 * `config` is opaque: the library never reads keys from it. Documented v1
 * option keys are written over it when building the sidecar payload.
 */
final class EmbedRequest
{
    /** @var list<string> */
    private const JS_RESERVED_WORDS = [
        'await', 'break', 'case', 'catch', 'class', 'const', 'continue',
        'debugger', 'default', 'delete', 'do', 'else', 'enum', 'export',
        'extends', 'false', 'finally', 'for', 'function', 'if', 'implements',
        'import', 'in', 'instanceof', 'interface', 'let', 'new', 'null',
        'package', 'private', 'protected', 'public', 'return', 'static',
        'super', 'switch', 'this', 'throw', 'true', 'try', 'typeof', 'var',
        'void', 'while', 'with', 'yield',
    ];

    /**
     * @param array<string, mixed> $config Arguments for the preset factory.
     */
    public function __construct(
        public readonly string $preset,
        public readonly string $containerId,
        public readonly array $config,
        public readonly string $assetBase = '/mapsight/plan',
        public readonly string $containerClassName = '',
        public readonly ?string $requestId = null,
        public readonly ?string $assetVersion = null,
        public readonly ?string $locale = null,
        public readonly ?string $deviceClass = null,
        public readonly ?string $requestUrl = null,
        public readonly ?string $pageOrigin = null,
        public readonly ?string $ogImage = null,
        public readonly ?string $scriptNonce = null,
    ) {
        self::assertJsIdentifier($this->preset, 'preset');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_:.-]*$/', $this->containerId) !== 1) {
            throw new \InvalidArgumentException(
                'containerId must match [A-Za-z][A-Za-z0-9_:.-]*',
            );
        }
        self::assertHeaderToken($this->requestId, 'requestId');
        self::assertHeaderToken($this->assetVersion, 'assetVersion');
    }

    /**
     * Preset is interpolated as a JS import binding and a `/assets/{preset}.js`
     * file name. Hyphens and reserved words are a SyntaxError in the boot script.
     */
    private static function assertJsIdentifier(string $value, string $field): void
    {
        if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a JavaScript identifier');
        }
        if (in_array($value, self::JS_RESERVED_WORDS, true)) {
            throw new \InvalidArgumentException($field . ' must not be a JavaScript reserved word');
        }
    }

    /**
     * Values that become HTTP headers (and query tokens). Reject CR/LF and
     * anything outside a conservative token alphabet.
     */
    private static function assertHeaderToken(?string $value, string $field): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,200}$/', $value) !== 1) {
            throw new \InvalidArgumentException(
                $field . ' must match [A-Za-z0-9._-]{1,200}',
            );
        }
    }
}
