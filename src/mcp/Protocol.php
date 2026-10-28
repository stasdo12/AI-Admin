<?php
namespace OcMcp;

/**
 * Protocol constants: supported MCP revisions, _meta keys and JSON-RPC error codes.
 *
 * The server is "dual-era":
 *  - legacy revisions (2024-11-05 .. 2025-11-25) use the initialize handshake;
 *  - the modern revision (2026-07-28) is stateless and carries the version in _meta.
 */
final class Protocol {
    const MODERN = ['2026-07-28'];
    const LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    const META_VERSION      = 'io.modelcontextprotocol/protocolVersion';
    const META_CLIENT_INFO  = 'io.modelcontextprotocol/clientInfo';
    const META_CLIENT_CAPS  = 'io.modelcontextprotocol/clientCapabilities';
    const META_SERVER_INFO  = 'io.modelcontextprotocol/serverInfo';

    // JSON-RPC 2.0
    const ERR_PARSE            = -32700;
    const ERR_INVALID_REQUEST  = -32600;
    const ERR_METHOD_NOT_FOUND = -32601;
    const ERR_INVALID_PARAMS   = -32602;
    const ERR_INTERNAL         = -32603;

    // MCP 2026-07-28 reserved range
    const ERR_HEADER_MISMATCH     = -32020;
    const ERR_UNSUPPORTED_VERSION = -32022;

    // Implementation-defined
    const ERR_UNAUTHORIZED = -32001;
    const ERR_RATE_LIMITED = -32005;

    public static function supported(): array {
        return array_merge(self::MODERN, self::LEGACY);
    }

    public static function isModern(string $version): bool {
        return in_array($version, self::MODERN, true);
    }

    public static function isLegacy(string $version): bool {
        return in_array($version, self::LEGACY, true);
    }
}
