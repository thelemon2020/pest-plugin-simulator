<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * The text of iOS's deep-link prompt, "Open in “App”?", in every language the Simulator's
 * runtime has.
 *
 * iOS asks the first time a device opens a URL scheme from simctl, and keeps the answer in
 * com.apple.launchservices.schemeapproval. Nothing in the prompt has an identifier, and its
 * shape (a system alert with the app's name in the title and two buttons) is a permission
 * prompt's too, whose second button is Allow. What is the same in every language is where
 * the words come from: CoreServices' SchemeApproval.strings, one per language (53 on iOS 26.5).
 *
 * @phpstan-import-type OpenPrompt from Driver
 */
final class SchemeApproval
{
    private const STRINGS = '/System/Library/Frameworks/CoreServices.framework/*.lproj/SchemeApproval.strings';

    /**
     * A placeholder in a title: `%@`, `%1$@`, or Finnish's `%[tt]@`, which inflects the name.
     */
    private const PLACEHOLDER = '/%(?:\d+\$)?(?:\[[^\]]*\])?@/';

    /**
     * One JSON object as plutil writes it for a strings file, which holds only strings.
     */
    private const OBJECT = '/\{(?:[^{}"]++|"(?:[^"\\\\]++|\\\\.)*+")*+\}/s';

    /** @var array<string, string> each title's pattern, built the first time it is matched */
    private static array $patterns = [];

    /**
     * Every language in one plutil call. A file plutil cannot read fails that call, so then
     * each file is read on its own and that one is skipped.
     *
     * @return list<OpenPrompt>
     */
    public static function read(Command $command, string $runtimeRoot, ?float $timeout = null): array
    {
        if ($runtimeRoot === '' || ! is_dir($runtimeRoot)) {
            return [];
        }

        $files = glob($runtimeRoot.self::STRINGS) ?: [];

        if ($files === []) {
            return [];
        }

        try {
            $tables = self::objects($command->run('plutil', ['-convert', 'json', '-o', '-', ...$files], null, $timeout));
        } catch (SimulatorException) {
            $tables = [];

            foreach ($files as $file) {
                try {
                    $tables = [...$tables, ...self::objects($command->run('plutil', ['-convert', 'json', '-o', '-', $file], null, $timeout))];
                } catch (SimulatorException) {
                }
            }
        }

        $prompts = [];

        foreach ($tables as $strings) {
            $prompt = self::prompt($strings);

            if ($prompt !== null) {
                $prompts[] = $prompt;
            }
        }

        return $prompts;
    }

    /**
     * One language's SchemeApproval.strings. simctl's openurl shows the title with no source;
     * the other names the app the link came from.
     *
     * @param  array<mixed>  $strings
     * @return OpenPrompt|null
     */
    public static function prompt(array $strings): ?array
    {
        $open = $strings['SCHEME_APPROVAL_OPEN_BUTTON'] ?? null;
        $titles = [];

        foreach (['SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE', 'SCHEME_APPROVAL_PROMPT_TITLE'] as $key) {
            if (is_string($strings[$key] ?? null) && $strings[$key] !== '') {
                $titles[] = $strings[$key];
            }
        }

        if (! is_string($open) || $open === '' || $titles === []) {
            return null;
        }

        return ['titles' => $titles, 'open' => $open];
    }

    /**
     * Whether a label is this title with an app's name in place of each placeholder.
     */
    public static function titled(string $label, string $title): bool
    {
        if (! isset(self::$patterns[$title])) {
            $parts = preg_split(self::PLACEHOLDER, $title) ?: [$title];
            self::$patterns[$title] = '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/us';
        }

        return preg_match(self::$patterns[$title], $label) === 1;
    }

    /**
     * plutil writes one object per file, one straight after another.
     *
     * @return list<array<mixed>>
     */
    private static function objects(string $output): array
    {
        preg_match_all(self::OBJECT, $output, $matches);
        $objects = [];

        foreach ($matches[0] as $object) {
            $decoded = json_decode($object, true);

            if (is_array($decoded)) {
                $objects[] = $decoded;
            }
        }

        return $objects;
    }
}
