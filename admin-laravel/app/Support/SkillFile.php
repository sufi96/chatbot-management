<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A SKILL.md file, in the open Agent Skills format: front matter between
 * "---" lines naming the skill and saying when to use it, then the
 * instructions. C⁴ adds one optional key, tools: the connector operations the
 * skill needs, so it is offered only to a bot that has them.
 *
 * ponytail: key: value front matter only; add symfony/yaml if real skills
 * need nested YAML.
 */
final class SkillFile
{
    public const NAME = '/^[a-z0-9-]{1,64}$/';

    public const MAX_BODY = 50000;

    /** @return array{name: string, description: string, tools: array, body: string} */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\x{FEFF}/u', '', $text));

        // A model's draft comes wrapped in a code fence, however firmly it is
        // told not to. Only a fence around the whole file is taken off.
        $text = preg_replace('/^\s*```[A-Za-z]*\n(.*?)\n```\s*$/s', '$1', $text);

        // "---  ": Markdown hard breaks, which drafting models put on every line.
        if (!preg_match('/^\s*---[ \t]*\n(.*?)\n---[ \t]*(?:\n|$)(.*)$/s', $text, $parts)) {
            throw new InvalidArgumentException('A skill starts with front matter: a "---" line, name: and description:, then "---".');
        }

        $fields = [];
        foreach (explode("\n", $parts[1]) as $line) {
            if (preg_match('/^([A-Za-z_-]+):\s*(.*)$/', $line, $match)) {
                $fields[strtolower($match[1])] = self::unquote(trim($match[2]));
            }
        }

        $name = $fields['name'] ?? '';
        if ($name === '') {
            throw new InvalidArgumentException('The skill has no name: line in its front matter.');
        }
        if (!preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException('A skill name is lowercase letters, digits and hyphens, up to 64: "application-status".');
        }

        $description = $fields['description'] ?? '';
        if ($description === '') {
            throw new InvalidArgumentException('The skill has no description: line saying when to use it.');
        }
        if (mb_strlen($description) > 1024) {
            throw new InvalidArgumentException('The description is over 1,024 characters.');
        }

        $body = trim($parts[2]);
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new InvalidArgumentException('The instructions are over 50,000 characters.');
        }

        $tools = trim($fields['tools'] ?? '', " []");
        $tools = $tools === '' ? [] : array_values(array_filter(array_map(
            fn ($tool) => self::unquote(trim($tool)), explode(',', $tools))));

        return ['name' => $name, 'description' => $description, 'tools' => $tools, 'body' => $body];
    }

    public static function render(array $skill): string
    {
        $lines = ['---', "name: {$skill['name']}", 'description: ' . $skill['description']];
        if (!empty($skill['tools'])) {
            $lines[] = 'tools: [' . implode(', ', $skill['tools']) . ']';
        }
        $lines[] = '---';

        return implode("\n", $lines) . "\n\n" . $skill['body'] . "\n";
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
