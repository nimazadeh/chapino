<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Asset URLs for the browser.
 *
 * Two jobs, both of which are bugs waiting to happen if each template does them by hand:
 *
 *  1. **The deployment prefix.** The application may be served from the document root or from a
 *     subfolder (`htdocs/chapino/public` - the usual XAMPP/Laragon layout). A hard-coded
 *     `/assets/css/tokens.css` works for the developer and 404s for the owner, so the prefix is
 *     always part of the URL.
 *  2. **Cache busting.** Shared hosting has no build step and no CDN purge, and browsers cache
 *     stylesheets aggressively. The file's modification time is the version: it changes when the file
 *     changes, needs no release process, and cannot be forgotten. When a real release process exists
 *     (phase 8) this becomes the release version instead.
 *
 * A missing file throws instead of returning a URL: a typo in a template must fail the test suite,
 * not produce a silent 404 in the browser.
 */
final class Asset
{
    public function __construct(private readonly string $publicPath)
    {
    }

    /** A URL usable in an HTML attribute, e.g. `/chapino/public/assets/css/tokens.css?v=1757842`. */
    public function url(string $basePath, string $file): string
    {
        $relative = '/' . ltrim($file, '/');

        return rtrim($basePath, '/') . $relative . '?v=' . $this->version($file);
    }

    /**
     * The version marker for one asset: its modification time, so a deploy that changes the file
     * changes the URL.
     */
    public function version(string $file): string
    {
        $path = $this->path($file);

        return (string) filemtime($path);
    }

    /**
     * Resolves an asset path inside the public directory, refusing anything that tries to leave it.
     *
     * Assets are referred to by constant names in templates, but a path built from a request parameter
     * would let an attacker enumerate or serve files outside the web root - so the rule is enforced
     * here rather than trusted at the call site.
     */
    public function path(string $file): string
    {
        if (preg_match('#^[A-Za-z0-9_./-]+$#', $file) !== 1 || str_contains($file, '..')) {
            throw new \InvalidArgumentException('Invalid asset path: ' . $file);
        }

        $path = $this->publicPath . '/' . ltrim($file, '/');

        if (!is_file($path)) {
            throw new \RuntimeException('Asset not found: ' . $file);
        }

        return $path;
    }
}
