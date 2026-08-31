<?php

namespace WorkflowConfigurator\Task\Args;

/**
 * The one generic enforcement of task parameter schemas
 * (specs/DynamicWorkflows.md §9.2, §9.4): requiredness, exact types, choices,
 * patterns, and rejection of unknown keys — the silent-typo gap (§9.1).
 *
 * Problems are keyed by the offending "args" key so the guided form can
 * attach each violation to its field (§9.4 rule 1); each message still names
 * the key, so flattened output remains self-explanatory.
 */
final class ArgsSchemaValidator
{
    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, list<string>> problems keyed by arg name; empty means valid
     */
    public static function validate(ArgsSchema $schema, array $args): array
    {
        $problems = [];

        $known = $schema->names();
        foreach (array_keys($args) as $key) {
            $key = (string) $key;
            if (!\in_array($key, $known, true)) {
                $problems[$key][] = \sprintf(
                    'unknown parameter "%s"%s',
                    $key,
                    [] === $known ? ' — this task takes no parameters' : '; known parameters: '.implode(', ', $known),
                );
            }
        }

        foreach ($schema->args as $arg) {
            if (!\array_key_exists($arg->name, $args)) {
                if ($arg->required) {
                    $problems[$arg->name][] = \sprintf('"%s" is required', $arg->name);
                }
                continue;
            }

            foreach (self::validateValue($arg, $args[$arg->name]) as $problem) {
                $problems[$arg->name][] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function validateValue(ArgDefinition $arg, mixed $value): array
    {
        switch ($arg->type) {
            case ArgType::Choice:
                if (!\in_array($value, $arg->choices ?? [], true)) {
                    $rendered = array_map(
                        static fn (int|string $choice): string => \is_string($choice) ? '"'.$choice.'"' : (string) $choice,
                        $arg->choices ?? [],
                    );

                    return [\sprintf('"%s" must be one of: %s', $arg->name, [] === $rendered ? '(none configured)' : implode(', ', $rendered))];
                }

                return [];

            case ArgType::Integer:
                return \is_int($value) ? [] : [\sprintf('"%s" must be an integer', $arg->name)];

            case ArgType::Text:
                if (!\is_string($value) || (null !== $arg->pattern && 1 !== preg_match($arg->pattern, $value))) {
                    return [\sprintf('"%s" must be %s', $arg->name, $arg->patternHint ?? 'a string'.(null !== $arg->pattern ? ' matching '.$arg->pattern : ''))];
                }

                return [];

            case ArgType::RuleCollection:
                // Structured beyond what a schema expresses; the task's
                // validateArgs() owns it (§9.2).
                return [];
        }
    }
}
