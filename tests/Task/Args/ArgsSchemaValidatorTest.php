<?php

namespace WorkflowConfigurator\Tests\Task\Args;

use WorkflowConfigurator\Task\Args\ArgDefinition;
use WorkflowConfigurator\Task\Args\ArgsSchema;
use WorkflowConfigurator\Task\Args\ArgsSchemaValidator;
use WorkflowConfigurator\Task\Args\ArgType;
use PHPUnit\Framework\TestCase;

/**
 * specs/DynamicWorkflows.md §9.2 (one generic enforcement of task parameter
 * schemas) and §9.4 rule 2 (unknown args keys are rejected).
 */
class ArgsSchemaValidatorTest extends TestCase
{
    private function schema(): ArgsSchema
    {
        return new ArgsSchema([
            new ArgDefinition('degrees', 'Rotation', ArgType::Choice, required: true, default: 180, choices: [90, 180, 270]),
            new ArgDefinition('class', 'Class', ArgType::Choice, required: true, choices: ['1', '2']),
            new ArgDefinition('x', 'X', ArgType::Integer),
            new ArgDefinition('postcode', 'Postcode', ArgType::Text, pattern: '/^[0-9A-Za-z]{5,7}$/', patternHint: '5-7 alphanumeric characters'),
            new ArgDefinition('postage', 'Postage', ArgType::RuleCollection),
        ]);
    }

    public function testValidArgsProduceNoProblems(): void
    {
        $problems = ArgsSchemaValidator::validate($this->schema(), [
            'degrees' => 90,
            'class' => '2',
            'x' => 40,
            'postcode' => 'AB12CD',
            'postage' => ['anything' => 'the task validates this'],
        ]);

        self::assertSame([], $problems);
    }

    public function testMissingRequiredArgIsReportedUnderItsKey(): void
    {
        $problems = ArgsSchemaValidator::validate($this->schema(), ['degrees' => 90]);

        self::assertArrayHasKey('class', $problems);
        self::assertSame(['"class" is required'], $problems['class']);
        self::assertArrayNotHasKey('x', $problems, 'Optional args may be absent.');
    }

    public function testChoiceIsStrictlyTyped(): void
    {
        // "1" vs 1: the trap §9.1 calls out — a string where the choice list
        // holds integers must fail, and vice versa.
        $problems = ArgsSchemaValidator::validate($this->schema(), ['degrees' => '90', 'class' => 1]);

        self::assertSame(['"degrees" must be one of: 90, 180, 270'], $problems['degrees']);
        self::assertSame(['"class" must be one of: "1", "2"'], $problems['class']);
    }

    public function testIntegerAndPatternViolations(): void
    {
        $problems = ArgsSchemaValidator::validate($this->schema(), [
            'degrees' => 180,
            'class' => '1',
            'x' => '40',
            'postcode' => 'no spaces allowed',
        ]);

        self::assertSame(['"x" must be an integer'], $problems['x']);
        self::assertSame(['"postcode" must be 5-7 alphanumeric characters'], $problems['postcode']);
    }

    public function testUnknownKeysAreRejected(): void
    {
        $problems = ArgsSchemaValidator::validate($this->schema(), [
            'degrees' => 180,
            'class' => '1',
            'degress' => 90,
        ]);

        self::assertArrayHasKey('degress', $problems);
        self::assertStringContainsString('unknown parameter "degress"', $problems['degress'][0]);
        self::assertStringContainsString('known parameters: degrees, class, x, postcode, postage', $problems['degress'][0]);
    }

    public function testEmptySchemaRejectsEveryKey(): void
    {
        $problems = ArgsSchemaValidator::validate(new ArgsSchema([]), ['anything' => true]);

        self::assertSame(['unknown parameter "anything" — this task takes no parameters'], $problems['anything']);
    }

    public function testRuleCollectionTypeIsLeftToTheTask(): void
    {
        // Even a scalar passes the generic layer: nested structures are the
        // task's validateArgs() business (§9.2).
        $problems = ArgsSchemaValidator::validate($this->schema(), [
            'degrees' => 180,
            'class' => '1',
            'postage' => 'not even an array',
        ]);

        self::assertArrayNotHasKey('postage', $problems);
    }
}
