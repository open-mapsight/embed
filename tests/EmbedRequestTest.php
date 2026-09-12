<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\EmbedRequest;
use PHPUnit\Framework\TestCase;

final class EmbedRequestTest extends TestCase
{
    public function test_rejects_preset_that_is_not_a_js_identifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('preset must be a JavaScript identifier');

        new EmbedRequest(
            preset: 'my-map',
            containerId: 'mapsight-embed-1',
            config: [],
        );
    }

    public function test_rejects_reserved_word_preset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('preset must not be a JavaScript reserved word');

        new EmbedRequest(
            preset: 'default',
            containerId: 'mapsight-embed-1',
            config: [],
        );
    }

    public function test_rejects_request_id_with_crlf(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requestId must match');

        new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-1',
            config: [],
            requestId: "abc\r\nX-Injected: yes",
        );
    }

    public function test_rejects_asset_version_with_spaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('assetVersion must match');

        new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-1',
            config: [],
            assetVersion: 'assets 9',
        );
    }

    public function test_accepts_header_safe_tokens(): void
    {
        $request = new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-1',
            config: [],
            requestId: 'req-1.2_3',
            assetVersion: 'assets-9',
        );

        $this->assertSame('req-1.2_3', $request->requestId);
        $this->assertSame('assets-9', $request->assetVersion);
    }
}
