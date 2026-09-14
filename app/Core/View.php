<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Server-side HTML rendering.
 *
 * A deliberately small seam: a template is a PHP file under `app/Views` that
 * outputs HTML, and the only thing this class adds is the two things a template
 * cannot be trusted to do consistently on its own - escaping and scoping.
 *
 * What this is NOT: a template engine, a theme system, or a place where design
 * processing happens. Everything a user designs is rendered in the browser
 * (C-6/C-7); these views serve ordinary pages - a landing page, a form, a table.
 *
 * Rules the templates follow (frontend rule):
 *   - the document declares `lang="fa"` and `dir="rtl"` once, in the layout;
 *   - any value that came from a database, a user or a request is printed
 *     through `View::e()` - never interpolated raw;
 *   - a template contains no query, no business rule and no authentication
 *     decision: by the time a view renders, the data is final.
 */
final class View
{
    /** @var list<string> */
    private array $stack = [];

    public function __construct(private readonly string $viewsPath)
    {
    }

    /**
     * Renders a template with the given data and returns the HTML.
     *
     * The template runs inside its own closure with only `$view` and the provided
     * keys in scope, so a template cannot read the controller's local variables
     * by accident - the boundary is enforced, not requested.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->fileFor($template);

        // A template that renders another template (a layout) must not be able to
        // include itself: that is an infinite loop which would exhaust memory.
        if (in_array($file, $this->stack, true)) {
            throw new \RuntimeException('Template includes itself: ' . $template);
        }

        $this->stack[] = $file;

        try {
            /**
             * @var string $__file
             * @var array<string, mixed> $__data
             * @var View $view
             */
            $__file = $file;
            $__data = $data;
            $view = $this;

            $render = static function (string $__file, array $__data, View $view): string {
                extract($__data, EXTR_SKIP);
                ob_start();

                try {
                    require $__file;
                } catch (\Throwable $e) {
                    ob_end_clean();

                    throw $e;
                }

                return (string) ob_get_clean();
            };

            return $render($__file, $__data, $view);
        } finally {
            array_pop($this->stack);
        }
    }

    /**
     * Escapes a value for HTML text and attribute contexts.
     *
     * ENT_QUOTES covers single quotes as well, because a template may legitimately
     * print a value into an attribute that uses them; UTF-8 is explicit so a
     * mis-configured default cannot silently produce invalid byte sequences.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Template names are code, not user input, but they are still validated:
     * a name that can walk the filesystem (`../config/config`) would let a future
     * mistake - a template name built from a request parameter - read any file on
     * the host.
     */
    private function fileFor(string $template): string
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $template) !== 1) {
            throw new \InvalidArgumentException('Invalid template name: ' . $template);
        }

        $file = $this->viewsPath . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }

        return $file;
    }
}
