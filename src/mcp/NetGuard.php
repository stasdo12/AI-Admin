<?php
namespace OcMcp;

/**
 * Outgoing requests to addresses the AI or a client gives (image links, OAuth client documents) may only reach the public
 * internet: never the shop's own server, its network or a cloud metadata service. One rule for every place that fetches.
 */
final class NetGuard {
    /** IPv4 ranges that are not the public internet besides PHP's private / reserved flags (CGNAT, test nets, multicast ...). */
    const BLOCKED_V4 = [['0.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15],
                        ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]];

    public static function isPublic(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);

            foreach (self::BLOCKED_V4 as [$net, $bits]) {
                $mask = -1 << (32 - $bits);

                if (($n & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        $l = strtolower($ip);

        // IPv4-mapped (::ffff:10.0.0.1) is judged by its IPv4; unique local fc00::/7, link-local fe80::/10, documentation, NAT64, loopback
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $l, $m)) {
            return self::isPublic($m[1]);
        }

        return !preg_match('/^(::1?$|::ffff:|f[cd]|fe[89ab]|2001:db8|64:ff9b)/', $l);
    }

    /** A name that only exists inside a network (localhost, *.local, *.internal, a bare word). */
    public static function isLocalName(string $host): bool {
        return $host === 'localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal' || strpos($host, '.') === false;
    }

    /** Every address of a host (IPv4 and IPv6). */
    public static function resolve(string $host): array {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = @gethostbynamel($host) ?: [];

        if (function_exists('dns_get_record')) {
            foreach ((@dns_get_record($host, DNS_AAAA) ?: []) as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /** The host's addresses only if it is a public name and every address is public, else []. */
    public static function resolvePublic(string $host): array {
        if (!filter_var($host, FILTER_VALIDATE_IP) && self::isLocalName($host)) {
            return [];
        }

        $ips = self::resolve($host);

        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                return [];
            }
        }

        return $ips;
    }
}
