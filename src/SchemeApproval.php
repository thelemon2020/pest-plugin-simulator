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
     * @return list<OpenPrompt>
     */
    public static function read(Command $command, string $runtimeRoot): array
    {
        if ($runtimeRoot === '' || ! is_dir($runtimeRoot)) {
            return [];
        }

        $prompts = [];

        foreach (glob($runtimeRoot.self::STRINGS) ?: [] as $file) {
            try {
                $strings = json_decode($command->run('plutil', ['-convert', 'json', '-o', '-', $file]), true);
            } catch (SimulatorException) {
                continue;
            }

            $prompt = is_array($strings) ? self::prompt($strings) : null;

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
        $parts = preg_split(self::PLACEHOLDER, $title) ?: [$title];
        $pattern = implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts));

        return preg_match('/^'.$pattern.'$/us', $label) === 1;
    }
}
