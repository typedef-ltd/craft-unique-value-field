<?php

namespace typedef\uniquevaluefield\fields;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\InlineEditableFieldInterface;
use craft\base\SortableFieldInterface;
use craft\errors\InvalidFieldException;
use craft\fields\conditions\TextFieldConditionRule;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use DateTime;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use typedef\uniquevaluefield\services\UniqueValueService;
use typedef\uniquevaluefield\web\assets\uniquevaluefield\UniqueValueFieldAsset;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Unique Value Field type
 *
 * @property-read array $elementValidationRules
 * @property-read null|string $settingsHtml
 * @property-read string $defaultErrorMessage
 * @property-read null|string $elementConditionRuleType
 */
class UniqueValueField extends Field implements InlineEditableFieldInterface, SortableFieldInterface
{
    public const FORMAT_PRESET_NONE = '';
    public const FORMAT_PRESET_UUID_V4 = 'uuidV4';
    public const FORMAT_PRESET_ISO_DATE = 'isoDate';
    public const FORMAT_PRESET_TIMECODE = 'timecode';
    public const FORMAT_PRESET_E164_PHONE = 'e164Phone';
    public const FORMAT_PRESET_SLUG = 'slug';
    public const FORMAT_PRESET_SEMVER = 'semver';
    public const FORMAT_PRESET_UPPER_ALPHANUM = 'upperAlpha';
    public const FORMAT_PRESET_EMAIL = 'email';
    public const FORMAT_PRESET_IP_ADDRESS = 'ipAddress';
    public const FORMAT_PRESET_ISBN10 = 'isbn10';
    public const FORMAT_PRESET_ISBN13 = 'isbn13';
    public const FORMAT_PRESET_HEX_COLOUR = 'hexColour';
    public const FORMAT_PRESET_NUMERIC_CODE = 'numericCode';
    public const FORMAT_PRESET_CUSTOM = 'custom';

    private const FORMAT_PRESET_FIXED_LENGTHS = [
        self::FORMAT_PRESET_UUID_V4 => 36,
        self::FORMAT_PRESET_ISO_DATE => 10,
        self::FORMAT_PRESET_TIMECODE => 8,
    ];

    /**
     * Whether uniqueness should be case-sensitive
     *
     * @var bool
     */
    public bool $caseSensitive = true;

    /**
     * Whether uniqueness check should consider only uses of the field in the same site
     *
     * @var bool
     */
    public bool $scopeSite = false;

    /**
     * Whether uniqueness check should consider only elements of the same type
     *
     * @var bool
     */
    public bool $scopeElementType = false;

    /**
     * Whether uniqueness check should consider only entries in the same section (only applies to Entries)
     *
     * @var bool
     */
    public bool $scopeEntrySection = false;

    /**
     * Whether uniqueness check should consider only entries of the same type (only applies to Entries)
     *
     * @var bool
     */
    public bool $scopeEntryType = false;

    /**
     * Optionally scope by another custom field
     *
     * @var int|null
     */
    public ?int $scopeByCustomFieldId = null;

    /**
     * If true, then instead of a validation error for a duplicate, a numbered suffix will be added to force the value to be unique
     *
     * @var bool
     */
    public bool $autoSuffixDuplicates = false;

    /**
     * Custom error message for validation failures on this field
     *
     * @var string
     */
    public string $customErrorMessage = '';

    /**
     * Whether to enforce format validation on top of uniqueness
     *
     * @var bool
     */
    public bool $enableFormatValidation = false;

    /**
     * Which pre-defined format to enforce
     *
     * @var string
     */
    public string $formatPreset = '';

    /**
     * The regular expression to use for custom format validation
     *
     * @var string
     */
    public string $customRegex = '';

    /**
     * Optional custom error message for format validation failure
     *
     * @var string
     */
    public string $formatErrorMessage = '';

    /**
     * Minimum character count for field value
     *
     * @var int|null
     */
    public ?int $minChars = null;

    /**
     * Maximum character count for field value
     *
     * @var int|null
     */
    public ?int $maxChars = null;

    /**
     * Whether the field should be readonly in the CP, after first save
     *
     * @var bool
     */
    public bool $readOnly = false;

    /**
     * @var string|null The input’s placeholder text
     */
    public ?string $placeholder = null;

    /**
     * @var bool Whether the input should use monospace font
     */
    public bool $code = false;

    public static function displayName(): string
    {
        return Craft::t('unique-value-field', 'Unique Value Field');
    }

    public function __construct(array $config = [])
    {
        // Config normalization
        if (isset($config['limitUnit'], $config['fieldLimit'])) {
            $config['maxChars'] = (int)$config['fieldLimit'] ?: null;
            unset($config['limitUnit'], $config['fieldLimit']);
        }

        // remove unused settings
        unset($config['maxLengthUnit'], $config['columnType']);

        parent::__construct($config);
    }

    public function init(): void
    {
        parent::init();

        if (isset($this->placeholder)) {
            $this->placeholder = StringHelper::shortcodesToEmoji($this->placeholder);
        }
    }

    public static function phpType(): string
    {
        return 'string|null';
    }

    public static function icon(): string
    {
        return __DIR__ . '/../icon-mask.svg';
    }

    /**
     * @throws InvalidConfigException
     */
    public function getSettings(): array
    {
        $settings = parent::getSettings();

        if (isset($settings['placeholder']) && !Craft::$app->getDb()->getSupportsMb4()) {
            $settings['placeholder'] = StringHelper::emojiToShortcodes($settings['placeholder']);
        }

        if ($this->getFixedCharacterLength() !== null) {
            $settings['minChars'] = null;
            $settings['maxChars'] = null;
        }

        return $settings;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        if ($this->getFixedCharacterLength() === null) {
            $rules[] = [['minChars'], 'integer', 'min' => 0];
            $rules[] = [['maxChars'], 'integer', 'min' => 1];

            if ($this->minChars !== null && $this->maxChars !== null) {
                $rules[] = [['maxChars'], 'integer', 'min' => $this->minChars];
            }
        }

        $rules[] = [
            ['customErrorMessage'],
            'string',
            'max' => 255,
        ];
        $rules[] = [
            ['formatPreset', 'customRegex', 'formatErrorMessage'],
            'string',
            'max' => 255,
        ];
        $rules[] = [['enableFormatValidation'], 'boolean'];
        $rules[] = [
            'customRegex',
            function($attribute) {
                if (!($this->enableFormatValidation && $this->formatPreset === self::FORMAT_PRESET_CUSTOM)) {
                    return;
                }
                $err = null;
                $compiled = self::compileUserRegex($this->customRegex, $err);
                if ($compiled === null) {
                    $this->addError('customRegex', $err ?: Craft::t('unique-value-field', 'Custom regex is invalid.'));
                }
            },
        ];

        return $rules;
    }

    /**
     * @throws SyntaxError
     * @throws Exception
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('unique-value-field/uniquevaluefield/settings.twig', [
            'field' => $this,
            'presets' => $this->formatPresets(),
            'fixedLengthPresets' => self::FORMAT_PRESET_FIXED_LENGTHS,
        ]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return $this->_normalizeValueInternal($value, $element, false);
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        return $this->_normalizeValueInternal($value, $element, true);
    }

    private function _normalizeValueInternal(mixed $value, ?ElementInterface $element, bool $fromRequest): mixed
    {
        if ($value !== null) {
            if (!$fromRequest) {
                $value = StringHelper::unescapeShortcodes(StringHelper::shortcodesToEmoji($value));
            }

            // Standardise newlines + trim
            $value = trim(preg_replace('/\R/u', "\n", $value));

            // Unicode NFC normalisation (Craft uses mbstring/intl; this is safe)
            if (class_exists(\Normalizer::class) && \Normalizer::isNormalized($value, \Normalizer::FORM_C) === false) {
                $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
            }

            // If preset is "slug", coerce to a canonical slug form
            if ($this->enableFormatValidation && $this->formatPreset === self::FORMAT_PRESET_SLUG) {
                // Lowercase only; avoid transliteration here to keep behaviour minimal and predictable
                $value = mb_strtolower($value, 'UTF-8');
                // Collapse consecutive hyphens and trim edge hyphens
                $value = preg_replace('/-+/', '-', $value);
                $value = trim($value, '-');
            }
        }

        return $value !== '' ? $value : null;
    }

    /**
     * @throws SyntaxError
     * @throws InvalidConfigException
     * @throws RuntimeError
     * @throws Exception
     * @throws LoaderError
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        Craft::$app->getView()->registerAssetBundle(UniqueValueFieldAsset::class);

        $placeholder = null;
        if ($this->placeholder !== null) {
            $placeholder = Craft::t('site', StringHelper::unescapeShortcodes($this->placeholder));
        }

        return Craft::$app->getView()->renderTemplate('unique-value-field/uniquevaluefield/input.twig', [
            'name' => $this->handle,
            'value' => (string)$value,
            'field' => $this,
            'placeholder' => $placeholder,
            'orientation' => $this->getOrientation($element),
            'element' => $element,
        ]);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value !== null) {
            $value = StringHelper::escapeShortcodes($value);
            if (!Craft::$app->getDb()->getSupportsMb4()) {
                $value = StringHelper::emojiToShortcodes($value);
            }

            $value = trim($value);
        }
        return $value;
    }

    public function getElementConditionRuleType(): ?string
    {
        return TextFieldConditionRule::class;
    }

    public function getElementValidationRules(): array
    {
        $stringRule = [
            'string',
            'encoding' => 'UTF-8',
        ];

        if ($this->getFixedCharacterLength() === null) {
            $stringRule['min'] = $this->minChars;
            $stringRule['max'] = $this->maxChars;
        }

        return [$stringRule, ['validateUniqueValue']];
    }

    /**
     * Custom validation for the field
     *
     * @param ElementInterface $element
     * @return void
     * @throws InvalidFieldException
     * @throws ErrorException
     */
    public function validateUniqueValue(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);

        // First validate format, if there is one set
        if ($error = $this->validateFormat($value)) {
            $element->addError($this->handle, Html::encode($error));
            return;
        }

        // Now validate uniqueness
        if ($elementUsingValue = UniqueValueService::isValueInUse(
            element: $element,
            fieldHandle: $this->handle,
            value: $value,
            caseSensitive: $this->caseSensitive,
            scopeSite: $this->scopeSite,
            scopeElementType: $this->scopeElementType,
            scopeEntrySection: $this->scopeEntrySection,
            scopeEntryType: $this->scopeEntryType,
            scopeByCustomFieldId: $this->scopeByCustomFieldId,
        )) {
            // If autoSuffixDuplicates is enabled AND we're using a format that can handle auto-suffixing
            $allowsAutoSuffixDuplicates = !$this->enableFormatValidation || in_array($this->formatPreset, [
                self::FORMAT_PRESET_NONE,
                self::FORMAT_PRESET_SLUG,
            ], true);
            if ($this->autoSuffixDuplicates && $allowsAutoSuffixDuplicates) {
                // Normalise in case there's already a numbered suffix
                $value = preg_replace('/^(.+)-\d+$/', '$1', $value);

                $increment = 0;
                do {
                    $increment++;
                    $suffix = '-' . $increment;

                    if ($this->maxChars === null) {
                        $suffixedValue = $value . $suffix;
                    } else {
                        $baseMaxChars = $this->maxChars - mb_strlen($suffix, 'UTF-8');
                        if ($baseMaxChars < 1) {
                            break;
                        }

                        $suffixedValue = mb_substr($value, 0, $baseMaxChars, 'UTF-8') . $suffix;
                    }

                    $elementUsingValue = UniqueValueService::isValueInUse(
                        element: $element,
                        fieldHandle: $this->handle,
                        value: $suffixedValue,
                        caseSensitive: $this->caseSensitive,
                        scopeSite: $this->scopeSite,
                        scopeElementType: $this->scopeElementType,
                        scopeEntrySection: $this->scopeEntrySection,
                        scopeEntryType: $this->scopeEntryType,
                        scopeByCustomFieldId: $this->scopeByCustomFieldId,
                    );

                    if (!$elementUsingValue) {
                        $element->setFieldValue($this->handle, $suffixedValue);
                        return;
                    }
                } while (true);
            }

            $element->addError($this->handle, $this->generateErrorMessage($value, $elementUsingValue));
        }
    }

    public function getDefaultErrorMessage(): string
    {
        return Craft::t('unique-value-field', 'This value ("{value}") is already in use by "{element}".');
    }

    /**
     * Generate the error message from either the default or a custom template
     *
     * @param mixed $value
     * @param Element $element
     * @return string
     */
    public function generateErrorMessage(mixed $value, Element $element): string
    {
        $errorMessageTemplate = $this->getDefaultErrorMessage();

        if ($this->customErrorMessage) {
            $errorMessageTemplate = $this->customErrorMessage;
        }

        $errorMessage = preg_replace(
            '/\{\s*value\s*\}/i',
            Html::encode((string)$value),
            $errorMessageTemplate,
        );

        if (!Craft::$app->getElements()->canView($element)) {
            return preg_replace(
                '/\{\s*element\s*\}/i',
                "...",
                $errorMessage,
            );
        }

        return preg_replace(
            '/\{\s*element\s*\}/i',
            Html::encode($element->title),
            $errorMessage,
        );
    }

    /**
     * Validate the string format if there is one set
     *
     * @param string|null $value
     * @return string|null
     * @throws ErrorException
     */
    public function validateFormat(?string $value): ?string
    {
        if (!$this->enableFormatValidation || $this->formatPreset === self::FORMAT_PRESET_NONE) {
            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        switch ($this->formatPreset) {
            case self::FORMAT_PRESET_UUID_V4:
                $valid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
                break;
            case self::FORMAT_PRESET_ISO_DATE:
                $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
                if ($valid) {
                    $date = DateTime::createFromFormat('!Y-m-d', $value);
                    $dateErrors = DateTime::getLastErrors();
                    $valid = $date !== false
                        && $date->format('Y-m-d') === $value
                        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));
                }
                break;
            case self::FORMAT_PRESET_TIMECODE:
                $valid = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value);
                break;
            case self::FORMAT_PRESET_E164_PHONE:
                $valid = preg_match('/^\+\d{1,15}$/', $value);
                break;
            case self::FORMAT_PRESET_SLUG:
                $valid = preg_match('/^[a-z0-9\-]+$/', $value);
                break;
            case self::FORMAT_PRESET_SEMVER:
                $valid = preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[\da-z\-]+(?:\.[\da-z\-]+)*)?(?:\+[\da-z\-]+(?:\.[\da-z\-]+)*)?$/i', $value);
                break;
            case self::FORMAT_PRESET_UPPER_ALPHANUM:
                $valid = preg_match('/^[A-Z0-9]+$/', $value);
                break;
            case self::FORMAT_PRESET_EMAIL:
                $valid = preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $value);
                break;
            case self::FORMAT_PRESET_IP_ADDRESS:
                $valid = filter_var($value, FILTER_VALIDATE_IP) !== false;
                break;
            case self::FORMAT_PRESET_ISBN10:
                $isbn = str_replace('-', '', strtoupper($value));
                $valid = preg_match('/^[0-9X-]+$/i', $value) === 1 && preg_match('/^\d{9}[0-9X]$/', $isbn) === 1;
                if ($valid) {
                    $sum = 0;
                    for ($i = 0; $i < 10; $i++) {
                        $digit = $isbn[$i] === 'X' ? 10 : (int)$isbn[$i];
                        $sum += $digit * (10 - $i);
                    }
                    $valid = $sum % 11 === 0;
                }
                break;
            case self::FORMAT_PRESET_ISBN13:
                $isbn = str_replace('-', '', $value);
                $valid = preg_match('/^[0-9-]+$/', $value) === 1 && preg_match('/^97[89]\d{10}$/', $isbn) === 1;
                if ($valid) {
                    $sum = 0;
                    for ($i = 0; $i < 13; $i++) {
                        $sum += (int)$isbn[$i] * ($i % 2 === 0 ? 1 : 3);
                    }
                    $valid = $sum % 10 === 0;
                }
                break;
            case self::FORMAT_PRESET_HEX_COLOUR:
                $valid = preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value);
                break;
            case self::FORMAT_PRESET_NUMERIC_CODE:
                $valid = preg_match('/^\d+$/', $value);
                break;
            case self::FORMAT_PRESET_CUSTOM:
                // Guard against huge inputs with expensive patterns
                $effectiveMax = $this->maxChars ?? 1024;
                if (mb_strlen($value, 'UTF-8') > $effectiveMax) {
                    return Craft::t('unique-value-field', 'Value is too long to validate against the custom regex format.');
                }

                $err = null;
                $pattern = self::compileUserRegex($this->customRegex, $err);
                if ($pattern === null) {
                    // Treat a broken pattern as a configuration error shown to editors
                    return $err ?: Craft::t('unique-value-field', 'Custom regex is invalid.');
                }

                set_error_handler(static function() {
                });
                $valid = @preg_match($pattern, $value) === 1;
                restore_error_handler();
                break;
            default:
                throw new ErrorException("Invalid format preset");
        }

        if (!$valid) {
            if ($this->formatErrorMessage !== '') {
                return $this->formatErrorMessage;
            }

            return Craft::t('unique-value-field', 'Value does not match the required format (“{label}”).', [
                'label' => $this->formatPresets()[$this->formatPreset] ?? '',
            ]);
        }

        return null;
    }

    public function allowCaseInsensitive(): bool
    {
        return version_compare(Craft::$app->getVersion(), '5.3', '>=');
    }

    public function getFixedCharacterLength(): ?int
    {
        if (!$this->enableFormatValidation) {
            return null;
        }

        return self::FORMAT_PRESET_FIXED_LENGTHS[$this->formatPreset] ?? null;
    }

    /**
     * Returns an associative array of format preset keys => labels
     */
    private function formatPresets(): array
    {
        return [
            self::FORMAT_PRESET_NONE => Craft::t('unique-value-field', '(None)'),
            self::FORMAT_PRESET_UUID_V4 => Craft::t('unique-value-field', 'UUID v4'),
            self::FORMAT_PRESET_ISO_DATE => Craft::t('unique-value-field', 'ISO 8601 Date (YYYY-MM-DD)'),
            self::FORMAT_PRESET_TIMECODE => Craft::t('unique-value-field', 'Timecode (HH:MM:SS)'),
            self::FORMAT_PRESET_UPPER_ALPHANUM => Craft::t('unique-value-field', 'Uppercase alphanumeric code'),
            self::FORMAT_PRESET_NUMERIC_CODE => Craft::t('unique-value-field', 'Numeric code'),
            self::FORMAT_PRESET_HEX_COLOUR => Craft::t('unique-value-field', 'Hex colour (e.g. #FFFFFF or #FFF)'),
            self::FORMAT_PRESET_SEMVER => Craft::t('unique-value-field', 'Semantic version (x.y.z)'),
            self::FORMAT_PRESET_E164_PHONE => Craft::t('unique-value-field', 'E.164 phone number'),
            self::FORMAT_PRESET_EMAIL => Craft::t('unique-value-field', 'Email address'),
            self::FORMAT_PRESET_SLUG => Craft::t('unique-value-field', 'Lowercase slug'),
            self::FORMAT_PRESET_IP_ADDRESS => Craft::t('unique-value-field', 'IPv4 / IPv6 address'),
            self::FORMAT_PRESET_ISBN10 => Craft::t('unique-value-field', 'ISBN-10'),
            self::FORMAT_PRESET_ISBN13 => Craft::t('unique-value-field', 'ISBN-13'),
            self::FORMAT_PRESET_CUSTOM => Craft::t('unique-value-field', 'Custom regex...'),
        ];
    }

    /**
     * Compiles a raw user-defined regex string into a full PCRE-compatible regex pattern.
     * Handles error detection and provides feedback if the regex is invalid.
     *
     * @param string $raw The raw regex string provided by the user.
     * @param string|null &$error A reference variable to capture any error messages.
     * @return string|null Returns the compiled regex string if successful, or null on failure.
     */
    private static function compileUserRegex(string $raw, ?string &$error = null): ?string
    {
        $error = null;
        $raw = trim($raw);

        if ($raw === '') {
            $error = Craft::t('unique-value-field', 'Custom regex cannot be empty.');
            return null;
        }

        // Choose a delimiter not present in the raw pattern
        $candidates = ['~', '@', '#', '%', '!', ';', '`', '|', '/'];
        $delim = null;
        foreach ($candidates as $d) {
            if (!str_contains($raw, $d)) {
                $delim = $d;
                break;
            }
        }
        if ($delim === null) {
            $delim = '/';
            $raw = str_replace('/', '\/', $raw);
        }

        $compiled = $delim . $raw . $delim . 'uD';

        // Try compiling once to surface PCRE errors
        set_error_handler(static function() {
        });
        $ok = @preg_match($compiled, '');
        restore_error_handler();

        if ($ok === false) {
            $code = preg_last_error();
            $error = match ($code) {
                PREG_INTERNAL_ERROR => Craft::t('unique-value-field', 'Regex internal error.'),
                PREG_BACKTRACK_LIMIT_ERROR => Craft::t('unique-value-field', 'Regex backtrack limit exceeded.'),
                PREG_RECURSION_LIMIT_ERROR => Craft::t('unique-value-field', 'Regex recursion limit exceeded.'),
                PREG_BAD_UTF8_ERROR => Craft::t('unique-value-field', 'Regex pattern is not valid UTF-8.'),
                PREG_BAD_UTF8_OFFSET_ERROR => Craft::t('unique-value-field', 'Regex pattern has an invalid UTF-8 offset.'),
                PREG_JIT_STACKLIMIT_ERROR => Craft::t('unique-value-field', 'Regex JIT stack limit exceeded.'),
                default => Craft::t('unique-value-field', 'Custom regex is invalid.'),
            };
            return null;
        }

        return $compiled;
    }
}
