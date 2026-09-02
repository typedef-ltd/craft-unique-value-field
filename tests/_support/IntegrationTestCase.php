<?php

namespace typedef\uniquevaluefield\tests\support;

use Codeception\Test\Unit;
use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use typedef\uniquevaluefield\fields\UniqueValueField;

abstract class IntegrationTestCase extends Unit
{
    private static int $handleCounter = 0;

    private function uniqueHandle(string $base): string
    {
        return $base . ++self::$handleCounter;
    }

    protected function testUser(): User
    {
        $user = User::find()->status(null)->one();
        self::assertInstanceOf(User::class, $user, 'Craft test installation should contain its install user.');
        return $user;
    }

    protected function primarySiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    protected function createUniqueField(array $config = []): UniqueValueField
    {
        $config['handle'] ??= $this->uniqueHandle('uniqueCode');
        $field = new UniqueValueField(array_merge([
            'name' => 'Unique Code',
        ], $config));

        if (!Craft::$app->getFields()->saveField($field)) {
            self::fail('Could not create Unique Value field: ' . implode('; ', $field->getErrorSummary(true)));
        }

        return $field;
    }

    protected function createPlainTextField(): PlainText
    {
        $field = new PlainText([
            'name' => 'Scope',
            'handle' => $this->uniqueHandle('scopeValue'),
        ]);

        if (!Craft::$app->getFields()->saveField($field)) {
            self::fail('Could not create Plain Text field: ' . implode('; ', $field->getErrorSummary(true)));
        }

        return $field;
    }

    /** @param FieldInterface[] $fields */
    protected function createEntryType(string $handle, array $fields): EntryType
    {
        $handle = $this->uniqueHandle($handle);
        $entryType = new EntryType([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'hasTitleField' => true,
        ]);

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            self::fail('Could not create entry type: ' . implode('; ', $entryType->getErrorSummary(true)));
        }

        $layout = $entryType->getFieldLayout();

        $tab = new FieldLayoutTab(['name' => 'Content']);
        $tab->setLayout($layout);
        $tab->setElements(array_map(
            static fn(FieldInterface $field) => new CustomField($field),
            $fields,
        ));

        $layout->setTabs([$tab]);
        $entryType->setFieldLayout($layout);

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            self::fail('Could not save entry type field layout: ' . implode('; ', $entryType->getErrorSummary(true)));
        }

        return $entryType;
    }

    /** @param EntryType[] $entryTypes */
    protected function createSection(string $handle, array $entryTypes, ?array $siteIds = null): Section
    {
        $siteIds ??= [$this->primarySiteId()];
        $siteSettings = array_map(
            static fn(int $siteId) => new Section_SiteSettings([
                'siteId' => $siteId,
                'enabledByDefault' => true,
                'hasUrls' => false,
            ]),
            $siteIds,
        );

        $handle = $this->uniqueHandle($handle);
        $section = new Section([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
            'propagationMethod' => PropagationMethod::None,
            'siteSettings' => $siteSettings,
            'entryTypes' => $entryTypes,
        ]);

        if (!Craft::$app->getEntries()->saveSection($section)) {
            self::fail('Could not create section: ' . implode('; ', $section->getErrorSummary(true)));
        }

        return $section;
    }

    protected function newEntry(Section $section, EntryType $entryType, array $fieldValues = [], ?int $siteId = null): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => $siteId ?? $this->primarySiteId(),
            'authorId' => $this->testUser()->id,
            'title' => 'Entry ' . uniqid('', true),
        ]);
        $entry->setScenario(Element::SCENARIO_LIVE);
        foreach ($fieldValues as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }
        return $entry;
    }

    protected function createEntry(Section $section, EntryType $entryType, array $fieldValues = [], ?int $siteId = null): Entry
    {
        $entry = $this->newEntry($section, $entryType, $fieldValues, $siteId);
        if (!Craft::$app->getElements()->saveElement($entry)) {
            self::fail('Could not create entry: ' . implode('; ', $entry->getErrorSummary(true)));
        }
        return $entry;
    }

    protected function createSecondarySite(): Site
    {
        $primary = Craft::$app->getSites()->getPrimarySite();
        $site = new Site([
            'name' => 'Secondary',
            'handle' => 'secondary',
            'language' => $primary->language,
            'baseUrl' => 'http://secondary.test/',
            'groupId' => $primary->groupId,
        ]);

        if (!Craft::$app->getSites()->saveSite($site)) {
            self::fail('Could not create secondary site: ' . implode('; ', $site->getErrorSummary(true)));
        }

        Craft::$app->getIsMultiSite(true);
        Craft::$app->getIsMultiSite(true, true);

        return $site;
    }
}
