<?php

namespace typedef\uniquevaluefield\tests\integration;

use Craft;
use craft\elements\Entry;
use typedef\uniquevaluefield\fields\UniqueValueField;
use typedef\uniquevaluefield\tests\support\IntegrationTestCase;

class CoreBehaviourTest extends IntegrationTestCase
{
    public function testDuplicateIsRejectedAndSelfEditIsAllowed(): void
    {
        $field = $this->createUniqueField();
        $type = $this->createEntryType('article', [$field]);
        $section = $this->createSection('articles', [$type]);
        $first = $this->createEntry($section, $type, [$field->handle => 'ABC-123']);

        $duplicate = $this->newEntry($section, $type, [$field->handle => 'ABC-123']);
        self::assertFalse(Craft::$app->getElements()->saveElement($duplicate));
        self::assertNotEmpty($duplicate->getErrors($field->handle));

        $first->setFieldValue($field->handle, 'ABC-123');
        self::assertTrue(
            Craft::$app->getElements()->saveElement($first),
            implode('; ', $first->getErrorSummary(true)),
        );
    }

    public function testDraftDoesNotConflictWithItsCanonicalEntry(): void
    {
        $field = $this->createUniqueField();
        $type = $this->createEntryType('article', [$field]);
        $section = $this->createSection('articles', [$type]);
        $entry = $this->createEntry($section, $type, [$field->handle => 'DRAFT-CODE']);

        $draft = Craft::$app->getDrafts()->createDraft($entry, $this->testUser()->id);
        $draft->setFieldValue($field->handle, 'DRAFT-CODE');

        self::assertTrue(
            Craft::$app->getElements()->saveElement($draft),
            implode('; ', $draft->getErrorSummary(true)),
        );
    }

    public function testCaseInsensitiveModeRejectsDifferentCase(): void
    {
        $field = $this->createUniqueField(['caseSensitive' => false]);
        if (!$field->allowCaseInsensitive()) {
            self::markTestSkipped('Case-insensitive uniqueness requires Craft 5.3+.');
        }

        $type = $this->createEntryType('article', [$field]);
        $section = $this->createSection('articles', [$type]);

        $this->createEntry($section, $type, [$field->handle => 'AbC']);
        $duplicate = $this->newEntry($section, $type, [$field->handle => 'abc']);

        self::assertFalse(Craft::$app->getElements()->saveElement($duplicate));
        self::assertNotEmpty($duplicate->getErrors($field->handle));
    }

    public function testEntryTypeScopeAllowsSameValueAcrossTypes(): void
    {
        $field = $this->createUniqueField(['scopeEntryType' => true]);
        $article = $this->createEntryType('article', [$field]);
        $product = $this->createEntryType('product', [$field]);
        $section = $this->createSection('content', [$article, $product]);

        $this->createEntry($section, $article, [$field->handle => 'SHARED']);
        $productEntry = $this->createEntry($section, $product, [$field->handle => 'SHARED']);

        self::assertInstanceOf(Entry::class, $productEntry);
    }

    public function testCustomFieldScopeAllowsSameValueOnlyInDifferentGroups(): void
    {
        $scopeField = $this->createPlainTextField();
        $field = $this->createUniqueField(['scopeByCustomFieldId' => $scopeField->id]);
        $type = $this->createEntryType('registration', [$scopeField, $field]);
        $section = $this->createSection('registrations', [$type]);

        $this->createEntry($section, $type, [
            $scopeField->handle => 'event-a',
            $field->handle => 'ABC',
        ]);

        $differentGroup = $this->createEntry($section, $type, [
            $scopeField->handle => 'event-b',
            $field->handle => 'ABC',
        ]);
        self::assertInstanceOf(Entry::class, $differentGroup);

        $duplicate = $this->newEntry($section, $type, [
            $scopeField->handle => 'event-a',
            $field->handle => 'ABC',
        ]);
        self::assertFalse(Craft::$app->getElements()->saveElement($duplicate));
        self::assertNotEmpty($duplicate->getErrors($field->handle));
    }

    public function testSiteScopingControlsCrossSiteUniqueness(): void
    {
        $secondarySite = $this->createSecondarySite();
        $primarySiteId = $this->primarySiteId();
        $secondarySiteId = (int)$secondarySite->id;

        // Global uniqueness: the same value on another site should conflict.
        $globalField = $this->createUniqueField([
            'scopeSite' => false,
        ]);

        $globalPrimaryType = $this->createEntryType('globalPrimaryArticle', [$globalField]);
        $globalSecondaryType = $this->createEntryType('globalSecondaryArticle', [$globalField]);

        $globalPrimarySection = $this->createSection(
            'globalPrimaryArticles',
            [$globalPrimaryType],
            [$primarySiteId],
        );

        $globalSecondarySection = $this->createSection(
            'globalSecondaryArticles',
            [$globalSecondaryType],
            [$secondarySiteId],
        );

        $this->createEntry(
            $globalPrimarySection,
            $globalPrimaryType,
            [$globalField->handle => 'GLOBAL-SHARED'],
            $primarySiteId,
        );

        $globalDuplicate = $this->newEntry(
            $globalSecondarySection,
            $globalSecondaryType,
            [$globalField->handle => 'GLOBAL-SHARED'],
            $secondarySiteId,
        );

        self::assertFalse($globalDuplicate->validate());
        self::assertNotEmpty($globalDuplicate->getErrors($globalField->handle));

        // Site-scoped uniqueness: the same value on another site should be allowed.
        $siteField = $this->createUniqueField([
            'scopeSite' => true,
        ]);

        $sitePrimaryType = $this->createEntryType('sitePrimaryArticle', [$siteField]);
        $siteSecondaryType = $this->createEntryType('siteSecondaryArticle', [$siteField]);

        $sitePrimarySection = $this->createSection(
            'sitePrimaryArticles',
            [$sitePrimaryType],
            [$primarySiteId],
        );

        $siteSecondarySection = $this->createSection(
            'siteSecondaryArticles',
            [$siteSecondaryType],
            [$secondarySiteId],
        );

        $this->createEntry(
            $sitePrimarySection,
            $sitePrimaryType,
            [$siteField->handle => 'SITE-SHARED'],
            $primarySiteId,
        );

        $siteDuplicate = $this->newEntry(
            $siteSecondarySection,
            $siteSecondaryType,
            [$siteField->handle => 'SITE-SHARED'],
            $secondarySiteId,
        );

        self::assertSame($secondarySiteId, (int)$siteDuplicate->siteId);
        self::assertTrue(
            $siteDuplicate->validate(),
            implode('; ', $siteDuplicate->getErrorSummary(true)),
        );
        self::assertEmpty($siteDuplicate->getErrors($siteField->handle));
    }

    public function testAutoSuffixUsesNextNumberAndRespectsMaximumLength(): void
    {
        $field = $this->createUniqueField([
            'autoSuffixDuplicates' => true,
            'maxChars' => 8,
        ]);
        $type = $this->createEntryType('article', [$field]);
        $section = $this->createSection('articles', [$type]);

        $this->createEntry($section, $type, [$field->handle => 'series-1']);
        $second = $this->createEntry($section, $type, [$field->handle => 'series-1']);
        self::assertSame('series-2', $second->getFieldValue($field->handle));

        $this->createEntry($section, $type, [$field->handle => 'abcdefgh']);
        $truncated = $this->createEntry($section, $type, [$field->handle => 'abcdefgh']);
        self::assertSame('abcdef-1', $truncated->getFieldValue($field->handle));
    }

    public function testRepresentativeFormatValidation(): void
    {
        $date = new UniqueValueField([
            'enableFormatValidation' => true,
            'formatPreset' => UniqueValueField::FORMAT_PRESET_ISO_DATE,
        ]);
        self::assertNull($date->validateFormat('2024-02-29'));
        self::assertNotNull($date->validateFormat('2025-02-29'));

        $ip = new UniqueValueField([
            'enableFormatValidation' => true,
            'formatPreset' => UniqueValueField::FORMAT_PRESET_IP_ADDRESS,
        ]);
        self::assertNull($ip->validateFormat('2001:db8::1'));
        self::assertNotNull($ip->validateFormat('999.1.1.1'));

        $isbn = new UniqueValueField([
            'enableFormatValidation' => true,
            'formatPreset' => UniqueValueField::FORMAT_PRESET_ISBN13,
        ]);
        self::assertNull($isbn->validateFormat('978-0-306-40615-7'));
        self::assertNotNull($isbn->validateFormat('978-0-306-40615-8'));

        $slug = new UniqueValueField([
            'enableFormatValidation' => true,
            'formatPreset' => UniqueValueField::FORMAT_PRESET_SLUG,
        ]);
        self::assertSame('hello-world', $slug->normalizeValue('  HELLO--WORLD  ', null));
        self::assertSame('hello-world', $slug->normalizeValueFromRequest('  HELLO--WORLD  ', null));

        $timecode = new UniqueValueField([
            'enableFormatValidation' => true,
            'formatPreset' => UniqueValueField::FORMAT_PRESET_TIMECODE,
        ]);
        self::assertNull($timecode->validateFormat('09:30:00'));
        self::assertNotNull($timecode->validateFormat('9:30:00'));
    }

    public function testEntrySectionScopeAllowsSameValueAcrossSections(): void
    {
        $field = $this->createUniqueField(['scopeEntrySection' => true]);

        $articleType = $this->createEntryType('article', [$field]);
        $newsType = $this->createEntryType('news', [$field]);

        $articles = $this->createSection('articles', [$articleType]);
        $news = $this->createSection('news', [$newsType]);

        $this->createEntry($articles, $articleType, [
            $field->handle => 'SHARED',
        ]);

        $differentSection = $this->createEntry($news, $newsType, [
            $field->handle => 'SHARED',
        ]);
        self::assertInstanceOf(Entry::class, $differentSection);

        $duplicate = $this->newEntry($articles, $articleType, [
            $field->handle => 'SHARED',
        ]);

        self::assertFalse(Craft::$app->getElements()->saveElement($duplicate));
        self::assertNotEmpty($duplicate->getErrors($field->handle));
    }

    public function testUniquenessUsesNormalisedValue(): void
    {
        $field = $this->createUniqueField([
            'enableFormatValidation' => true,
            'formatPreset' => UniqueValueField::FORMAT_PRESET_SLUG,
        ]);

        $type = $this->createEntryType('article', [$field]);
        $section = $this->createSection('articles', [$type]);

        $this->createEntry($section, $type, [
            $field->handle => 'hello-world',
        ]);

        $duplicate = $this->newEntry($section, $type, [
            $field->handle => '  HELLO--WORLD  ',
        ]);

        self::assertFalse(Craft::$app->getElements()->saveElement($duplicate));
        self::assertNotEmpty($duplicate->getErrors($field->handle));
    }

    public function testMultipleEmptyValuesAreAllowed(): void
    {
        $field = $this->createUniqueField();
        $type = $this->createEntryType('article', [$field]);
        $section = $this->createSection('articles', [$type]);

        $first = $this->createEntry($section, $type, [
            $field->handle => '',
        ]);

        $second = $this->createEntry($section, $type, [
            $field->handle => '',
        ]);

        self::assertInstanceOf(Entry::class, $first);
        self::assertInstanceOf(Entry::class, $second);
    }

    public function testFixedLengthPresetIgnoresConfiguredCharacterLimits(): void
    {
        $field = $this->createUniqueField([
            'enableFormatValidation' => true,
            'formatPreset' => UniqueValueField::FORMAT_PRESET_UUID_V4,
            'minChars' => 1,
            'maxChars' => 8,
        ]);

        $type = $this->createEntryType('article', [$field]);
        $section = $this->createSection('articles', [$type]);

        $entry = $this->createEntry($section, $type, [
            $field->handle => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        self::assertInstanceOf(Entry::class, $entry);
    }
}