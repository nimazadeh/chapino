<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Settings;

/**
 * Applies default settings to an installation.
 *
 * A seeder is not a migration, and the difference is the whole design:
 *
 *   - a migration changes the SHAPE of the database and runs exactly once (it is recorded);
 *   - a seed fills in VALUES and can run any number of times, because an operator may run the
 *     installer again on a live installation, or copy a database by hand, or simply wonder whether
 *     the defaults were applied.
 *
 * So the rule enforced here is: **a default is only written when the key is absent**. A value the
 * operator changed is never replaced by the seeder, and running it twice changes nothing the second
 * time. That makes "apply the defaults" a safe thing to type on a live installation.
 *
 * Keys whose correct value is an owner decision are seeded with an empty value and marked
 * `owner_input`, so the installation has the shape the code expects while nobody has silently
 * invented a price, a commission rate or a tax rule.
 */
final class Seeder
{
    public function __construct(
        private readonly Settings $settings,
        private readonly string $seedsPath,
    ) {
    }

    /**
     * Seed files, in execution order.
     *
     * @return list<string> names without the .php suffix
     */
    public function available(): array
    {
        if (!is_dir($this->seedsPath)) {
            return [];
        }

        $files = [];
        foreach (scandir($this->seedsPath) ?: [] as $entry) {
            if (preg_match('/^\d{4}_[a-z0-9_]+\.php$/', $entry) === 1) {
                $files[] = substr($entry, 0, -4);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Every default this installation would apply, merged from the seed files in order.
     *
     * @return array<string, array{value: string, note: string, owner_input: bool, source: string}>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->available() as $name) {
            foreach ($this->load($name) as $key => $definition) {
                // Two files defining the same key is a bug that would otherwise be invisible: the
                // later file would silently win, and the first file would become a comment.
                if (isset($defaults[$key])) {
                    throw new SeedException(
                        'کلید «' . $key . '» در بیش از یک فایل seed تعریف شده است ('
                        . $defaults[$key]['source'] . ' و ' . $name . ')',
                        $name,
                    );
                }

                $definition['source'] = $name;
                $defaults[$key] = $definition;
            }
        }

        return $defaults;
    }

    /**
     * Keys that are still missing from the settings table.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $missing = [];
        foreach (array_keys($this->defaults()) as $key) {
            if (!$this->settings->has($key)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Writes every missing default.
     *
     * @return array{added: list<string>, kept: list<string>, owner_input: list<string>}
     *         `owner_input` lists keys that exist but are deliberately empty, because the value is
     *         the owner's decision - the installer prints them instead of pretending they are done.
     */
    public function run(): array
    {
        $added = [];
        $kept = [];
        $ownerInput = [];

        foreach ($this->defaults() as $key => $definition) {
            if ($this->settings->setIfMissing($key, $definition['value'])) {
                $added[] = $key;
            } else {
                $kept[] = $key;
            }

            if ($definition['owner_input']) {
                $ownerInput[] = $key;
            }
        }

        return ['added' => $added, 'kept' => $kept, 'owner_input' => $ownerInput];
    }

    /**
     * Reads and validates one seed file.
     *
     * @return array<string, array{value: string, note: string, owner_input: bool}>
     */
    private function load(string $name): array
    {
        $path = $this->seedsPath . '/' . $name . '.php';
        if (!is_file($path)) {
            throw new SeedException('فایل seed پیدا نشد: ' . $name, $name);
        }

        /** @var mixed $seed */
        $seed = require $path;
        if (!is_array($seed)) {
            throw new SeedException('فایل seed باید یک آرایه برگرداند: ' . $name, $name);
        }

        $definitions = [];
        foreach ($seed as $key => $definition) {
            if (!is_string($key)) {
                throw new SeedException('کلید تنظیمات باید رشته باشد، در فایل ' . $name, $name);
            }

            if (!is_array($definition) || !array_key_exists('value', $definition)) {
                throw new SeedException(
                    'مقدار «' . $key . '» باید آرایه‌ای با کلید value باشد: '
                    . "['value' => '...', 'note' => '...'] — فایل " . $name,
                    $name,
                );
            }

            try {
                Settings::assertKey($key);
            } catch (\InvalidArgumentException $e) {
                throw new SeedException($e->getMessage() . ' (فایل ' . $name . ')', $name, $e);
            }

            $definitions[$key] = [
                'value' => (string) $definition['value'],
                'note' => (string) ($definition['note'] ?? ''),
                'owner_input' => (bool) ($definition['owner_input'] ?? false),
            ];
        }

        return $definitions;
    }
}
