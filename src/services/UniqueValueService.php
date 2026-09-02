<?php

namespace typedef\uniquevaluefield\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\errors\InvalidFieldException;
use Exception;
use Throwable;
use yii\base\ErrorException;

/**
 * Unique Value Service
 */
class UniqueValueService
{
    /**
     * Whether a particular field value is unique, according to various uniqueness criteria
     *
     * @param ElementInterface $element
     * @param string $fieldHandle
     * @param mixed $value
     * @param bool $caseSensitive
     * @param bool $scopeSite
     * @param bool $scopeElementType
     * @param bool $scopeEntrySection
     * @param bool $scopeEntryType
     * @param int|null $scopeByCustomFieldId
     * @return false|null|ElementInterface null if validation was skipped, false if the value is not in use (i.e. is unique), or the element that is already using this value
     * @throws ErrorException
     * @throws InvalidFieldException
     */
    public static function isValueInUse(
        ElementInterface $element,
        string           $fieldHandle,
        mixed            $value,
        bool             $caseSensitive = true,
        bool             $scopeSite = false,
        bool             $scopeElementType = false,
        bool             $scopeEntrySection = false,
        bool             $scopeEntryType = false,
        ?int             $scopeByCustomFieldId = null,
    ): false|null|ElementInterface {
        if ($value === null || $value === '') {
            return false;
        }

        // Make sure there's a field layout on the element
        if (!method_exists($element::class, 'getFieldLayout')) {
            return null;
        }
        try {
            $fieldLayout = $element->getFieldLayout();
            if (!$fieldLayout) {
                return null;
            }
        } catch (Exception) {
            // Absorb errors
            return null;
        }

        // If the custom field that we're using for 'composite uniqueness' doesn't exist on the element,
        // then remove this constraint
        if ($scopeByCustomFieldId) {
            $customFieldExistsOnElement = false;
            if ($fieldLayout = $element->getFieldLayout()) {
                foreach ($fieldLayout->getCustomFields() as $field) {
                    if ($field->id === $scopeByCustomFieldId) {
                        $customFieldExistsOnElement = true;
                        break;
                    }
                }
            }
            if (!$customFieldExistsOnElement) {
                $scopeByCustomFieldId = null;
            }
        }

        // If we've scoped the query to the element type, we only need one query and then return the result
        if ($scopeElementType) {
            $query = self::createQuery(
                elementType: $element::class,
                element: $element,
                fieldHandle: $fieldHandle,
                value: $value,
                caseSensitive: $caseSensitive,
                scopeSite: $scopeSite,
                scopeEntrySection: $scopeEntrySection,
                scopeEntryType: $scopeEntryType,
                scopeByCustomFieldId: $scopeByCustomFieldId,
            );

            if ($elementWithSameValue = $query->one()) {
                return $elementWithSameValue;
            }

            return false;
        }

        $entryTypeIdsForHandle = self::entryTypeIdsUsingFieldHandle($fieldHandle);

        /*
         * If not scoping by Element type, we need to run the query separately for all Element types, because there is
         * no built-in Craft way to run find() on all element types at once. So we fetch all element types and then
         * filter by only those which can run queries and have the field in question in their field layout
         */
        $elementTypesToQuery = array_filter(
            Craft::$app->getElements()->getAllElementTypes(),
            static function($type) use ($fieldHandle, $entryTypeIdsForHandle) {
                // Basic checks
                if (
                    !is_subclass_of($type, Element::class)
                    || !method_exists($type, 'find')
                    || !method_exists($type, 'getFieldLayout')
                ) {
                    return false;
                }

                // For entries, return early if the field is not used by any entry types
                if ($type::instance() instanceof Entry) {
                    return !empty($entryTypeIdsForHandle);
                }

                // Element types can have multiple field layouts (for example, Assets by volume).
                // Check every saved layout rather than assuming the static element instance is representative.
                try {
                    foreach (Craft::$app->getFields()->getLayoutsByType($type) as $fieldLayout) {
                        foreach ($fieldLayout->getCustomFields() as $field) {
                            if ($field->handle === $fieldHandle) {
                                return true;
                            }
                        }
                    }
                } catch (Exception) {
                    // Absorb errors
                    return false;
                }

                return false;
            }
        );

        // Loop over valid element types until we find query results
        // If any of the queries contain matches, then it's a validation failure
        foreach ($elementTypesToQuery as $elementType) {
            $query = self::createQuery(
                elementType: $elementType,
                element: $element,
                fieldHandle: $fieldHandle,
                value: $value,
                caseSensitive: $caseSensitive,
                scopeSite: $scopeSite,
                scopeEntrySection: $scopeEntrySection,
                scopeEntryType: $scopeEntryType,
                scopeByCustomFieldId: $scopeByCustomFieldId,
            );

            /** @var ElementInterface $elementWithSameValue */
            if ($elementWithSameValue = $query->one()) {
                return $elementWithSameValue;
            }
        }

        return false;
    }

    /**
     * Create the query for a particular element type
     *
     * @param string $elementType
     * @param ElementInterface $element
     * @param string $fieldHandle
     * @param mixed $value
     * @param bool $caseSensitive
     * @param bool $scopeSite
     * @param bool $scopeEntrySection
     * @param bool $scopeEntryType
     * @param int|null $scopeByCustomFieldId
     * @return ElementQueryInterface
     * @throws InvalidFieldException
     * @throws ErrorException
     */
    private static function createQuery(
        string $elementType,
        ElementInterface $element,
        string $fieldHandle,
        mixed $value,
        bool $caseSensitive = true,
        bool $scopeSite = false,
        bool $scopeEntrySection = false,
        bool $scopeEntryType = false,
        ?int $scopeByCustomFieldId = null,
    ): ElementQueryInterface {
        // Build the base query for any uses of the field with the same value
        /** @var Element $elementType */
        $query = $elementType::find()
            ->status(null)
            ->limit(1)
        ;

        // Scope uniqueness to site, or explicitly query all sites for global uniqueness
        if ($scopeSite) {
            $query->siteId($element->siteId);
        } else {
            $query->siteId('*');
        }

        // If it's an entry
        if ($query instanceof EntryQuery && $element instanceof Entry && $elementType::instance() instanceof Entry) {
            // Narrow to only entry types that actually use the field (for performance)
            $idsUsingField = self::entryTypeIdsUsingFieldHandle($fieldHandle);
            if (!empty($idsUsingField)) {
                $query->typeId($idsUsingField);
            }

            // Scope uniqueness to section
            if ($scopeEntrySection) {
                $query->sectionId($element->sectionId);
            }

            // Scope uniqueness to entry type
            if ($scopeEntryType) {
                $query->typeId($element->typeId);
            }
        }

        // Scope uniqueness by another custom field
        if ($scopeByCustomFieldId !== null) {
            $fieldToScopeBy = Craft::$app->getFields()->getFieldById($scopeByCustomFieldId);
            if ($fieldToScopeBy) {
                // It shouldn't be possible to scope a field's uniqueness by itself, but just in case...
                if ($fieldToScopeBy->handle === $fieldHandle) {
                    throw new ErrorException('You cannot scope uniqueness of this field to itself. Please choose another field to scope by.');
                }

                $layout = $element->getFieldLayout();
                $fieldExistsOnType = $layout && in_array($fieldToScopeBy->handle, array_map(static fn($f) => $f->handle, $layout->getCustomFields()), true);
                if ($fieldExistsOnType) {
                    $query[$fieldToScopeBy->handle] = $element->getFieldValue($fieldToScopeBy->handle);
                }
            }
        }

        // Dynamically set the handle of the field we want to include in our WHERE clause
        // If Craft 5.3 or above, then allow the case-insensitive option
        if (!$caseSensitive && version_compare(Craft::$app->getVersion(), '5.3', '>=')) {
            $query[$fieldHandle] = [
                'value' => $value,
                'caseInsensitive' => true,
            ];
        } else {
            $query[$fieldHandle] = $value;
        }

        // Exclude the current element if it's not new
        // Note: we factor in the canonical ID to treat drafts as the same element as their root/parent element
        if ($element->id) {
            if ($element->getCanonicalId() !== $element->id) {
                $query->id(['not', $element->id, $element->getCanonicalId()]);
            } else {
                $query->id(['not', $element->id]);
            }
        }

        return $query;
    }

    /**
     * Returns an array of entry type IDs that use a specific field handle in their field layout.
     *
     * @param string $fieldHandle The handle of the custom field to search for.
     * @return int[] An array of entry type IDs where the specified field handle is used.
     */
    private static function entryTypeIdsUsingFieldHandle(string $fieldHandle): array
    {
        static $cache = [];

        if (array_key_exists($fieldHandle, $cache)) {
            return $cache[$fieldHandle];
        }

        $ids = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            try {
                $layout = $entryType->getFieldLayout();

                foreach ($layout->getCustomFields() as $field) {
                    if ($field->handle === $fieldHandle) {
                        $ids[] = (int)$entryType->id;
                        break;
                    }
                }
            } catch (Throwable) {
                // Absorb weird configs
                continue;
            }
        }

        return $cache[$fieldHandle] = $ids;
    }
}
