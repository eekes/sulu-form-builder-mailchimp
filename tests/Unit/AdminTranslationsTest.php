<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderMailchimpBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sulu's admin parses every text of the "admin" domain as an ICU message; "{{ company }}" is not
 * one. Write a placeholder as %placeholder:company% - the form builder shows it as {{ company }}.
 */
#[CoversNothing]
final class AdminTranslationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogues(): iterable
    {
        foreach (glob(\dirname(__DIR__, 2).'/translations/admin.*.json') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('catalogues')]
    public function testEveryAdminTextIsAValidIcuMessage(string $path): void
    {
        $messages = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);

        foreach ($messages as $key => $message) {
            self::assertIsString($message);
            self::assertTrue(self::isIcu($message), \sprintf('"%s" in %s is not a valid ICU message: %s', $key, basename($path), $message));
        }
    }

    private static function isIcu(string $message): bool
    {
        try {
            new \MessageFormatter('en', $message);

            return true;
        } catch (\IntlException) {
            return false;
        }
    }
}
