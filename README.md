# Unique Value Field for Craft CMS

A custom text field that enforces unique values, with flexible scoping and built-in format validation presets. Ideal for SKUs, registration codes, slugs, ISBNs, and other identifiers you must keep unique across your content.

## Key features

* **Uniqueness enforcement** on save
* **Flexible scoping** — restrict uniqueness by:
    * Site (per-site uniqueness)
    * Element type
    * Entry Section and/or Entry Type
    * Another **custom field** (for grouped uniqueness, e.g. unique per region or group code)
* **Case-sensitivity toggle** (Craft **5.3+**): choose case-sensitive or case-insensitive checks
* **Auto-suffix duplicates** (opt-in): automatically append `-1`, `-2`, … instead of failing validation (useful for slug-like field values)
* Optional **format validation** with presets and custom regex:
    * UUID v4, ISO date `YYYY-MM-DD`, Timecode `HH:MM:SS`, E.164 phone, lowercase slug, semantic version, uppercase alphanumeric code, numeric code, hex colour, email, IPv4/IPv6, checksum-validated ISBN-10/13, or **Custom regex** to provide your own validation
* **Length limits**: min/max characters
* **Read-only in the Control Panel** after first save, with a handy copy button for retrieving the value
* Designed to work with Craft **element types that support custom fields**, including Entries, Categories, Users, and Assets, and across **multi-site** setups
* Clean CP UI with inline guidance

### Notes

- Under the hood, drafts/revisions are handled safely by excluding the element’s canonical ID from comparisons.
- Scopes are combined. If you enable more than one scope, a value only conflicts when all enabled scope conditions match. For example, if uniqueness is scoped by Site and Entry Type, the same value may be used on another site or in another Entry Type, but not by another element with the same Site and Entry Type.

## Requirements

* PHP >=8.2
* Craft CMS ^5.0.0
* Case-insensitive uniqueness requires Craft **5.3+**

## Installation

Install Unique Value Field from the Craft Plugin Store, or with Composer:

```bash
composer require typedef/craft-unique-value-field
php craft plugin/install unique-value-field
```

## How to use

1. **Create the field**
   Go to **CP → Settings → Fields → New field**, choose **Unique Value Field**, and give it a handle.

2. **Configure field settings**:
    * **Scope** — limit uniqueness by Site, Element Type, Section, Entry Type, or another custom field used as a grouping key. Multiple enabled scopes are combined, so all of them must match for two values to be considered conflicting.
    * **Case sensitive** — toggle (Craft 5.3+).
    * **Auto suffix duplicates** — append `-1`, `-2`, … instead of erroring.
    * **Format validation** — enable and pick a **preset** or supply a **Custom regex**; optionally set a **custom error message**.
    * **Min/Max characters** — enforce length limits for variable-length values and formats. Fixed-length presets such as UUIDs, ISO dates, and timecodes enforce their own length.
    * **Read-only** — prevent Control Panel edits after the first save (a copy button is provided).
    * **Placeholder / Code style** — optional input presentation tweaks.

3. **Add the field to your element layout**
   Assign the field to your desired Entry Type / User / Asset layout, etc.

4. **Edit content**
    * On save, the plugin validates the value and checks uniqueness according to your configured scope.

### Custom error messages

You can include tokens in the **Custom error message**:

* `{value}` — the conflicting value
* `{element}` — the title of the conflicting element

Example:
`The code "{value}" is already used by "{element}". Please choose another.`

## Some examples of use

* **SKUs per site**
  Create an SKU field and enable uniqueness with **scope by Site** so the same SKU can exist on different sites but remains unique within each site.

* **Unique ISBN for a Library section**
  Create an “ISBN” field. Enable **Format validation → ISBN-13**, and scope uniqueness to the **Books** section.

* **Reference numbers per group**
  Create “Reference Number” and “Group Code” fields. In **Reference Number** settings, **scope by custom field** to **Group Code** so reference numbers only need to be unique within each group.

* **Auto-suffix human-friendly IDs**
  Enable **Auto suffix duplicates** so `series-1` becomes `series-2` automatically on clash.

## Support & licence

* **Vendor:** Typedef Limited — [support@typedef.co](mailto:support@typedef.co)
* **License:** MIT
* Issues and feature requests are welcome.
