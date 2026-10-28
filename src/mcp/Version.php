<?php
namespace OcMcp;

/**
 * Version of the module. NUMBER is set by hand for a release; BUILD is written by build/build.sh into the archive copy
 * (date and commit), so the owner can tell yesterday's archive from today's without unpacking it. "dev" in the repository.
 */
final class Version {
    const NUMBER = '1.0.1';
    const BUILD = 'dev';

    /** FREE / demo build. The free branch sets this true: read-only distribution, premium tabs shown but disabled. */
    const FREE = true;

    public static function full(): string {
        return self::NUMBER . (self::BUILD !== '' ? ' (' . self::BUILD . ')' : '');
    }
}
