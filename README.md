# HM Primary Category

Lets an editor mark one term per taxonomy as a post's **primary** one, and makes
WordPress return that term first.

`get_the_category()` is a thin wrapper over `get_the_terms()`, so a single
`get_the_terms` filter puts the chosen term at index 0 for every caller — core
blocks, feeds, archive listings, your own templates — without any of them opting
in. Nothing needs to know this plugin exists.

Enabled for `category` out of the box; opt in any other taxonomy with a filter.

The chosen term is only honoured while it is still one of the post's terms.
Untick it and the post falls back to its first term; tick it again and the
editor's choice is restored. There is no meta to clean up.

## Requirements

- PHP 8.1+
- WordPress 6.6+

## Install

```bash
composer require humanmade/wp-primary-category
```

The package is a `wordpress-plugin`, so `composer/installers` puts it in
`wp-content/plugins/`. Activate it as usual.

## Choosing the taxonomies

`hm_primary_term_taxonomies` filters the list, and is evaluated on every call —
hook it wherever suits. Anything that is not a registered taxonomy is dropped, so
a typo degrades to "no primary term for that taxonomy" rather than an error.

One exception to "wherever suits": hook it before `init` priority 20, which is
when the post meta is registered. A taxonomy added after that gets term ordering
but no meta, and so no REST field and no editor picker.

```php
// Also allow a primary tag.
add_filter( 'hm_primary_term_taxonomies', function ( array $taxonomies ): array {
	$taxonomies[] = 'post_tag';

	return $taxonomies;
} );

// Leave categories alone; only sectors get a primary term.
add_filter( 'hm_primary_term_taxonomies', function ( array $taxonomies ): array {
	return array_values( array_diff( $taxonomies, [ 'category' ] ) );
} );

// Turn the feature off entirely.
add_filter( 'hm_primary_term_taxonomies', '__return_empty_array' );
```

## API

All functions live in the `HM\Primary_Term` namespace.

```php
const META_PREFIX = '_hm_primary_';

// The enabled taxonomies, filtered and validated.
function taxonomies(): array;

// The post meta key for a taxonomy, e.g. `_hm_primary_category`.
function meta_key( string $taxonomy ): string;

// The chosen term if the post still has it, else the post's first term, else null.
function get_primary_term( int $post_id, string $taxonomy = 'category' ): ?\WP_Term;

// The same, as an ID; 0 when the post has no terms in the taxonomy.
function get_primary_term_id( int $post_id, string $taxonomy = 'category' ): int;

// Record a choice. False if the term does not exist, is in another taxonomy, or
// is not assigned to the post. A term ID of 0 clears the choice.
function set_primary_term( int $post_id, int $term_id, string $taxonomy = 'category' ): bool;

// The `get_the_terms` filter. Hooked for you; you should not need to call it.
function sort_primary_term_first( $terms, $post_id, $taxonomy );
```

Prefer `get_primary_term()` when you want the intent to be explicit at the call
site. The ordering filter is powerful precisely because it is invisible, which
also makes it easy to miss when reading someone else's template.

The choice is stored in post meta keyed `_hm_primary_{$taxonomy}`, registered
for every post type the taxonomy is attached to and exposed in the REST API.

## The editor picker

The control renders inside the core taxonomy panel, directly below the term
selector — the same place Yoast SEO puts its own primary category control. It
lists the terms the post already has and writes the chosen ID to the meta.

It appears for every enabled taxonomy, hierarchical or flat, on every post type
that taxonomy is attached to. A post with no terms in the taxonomy gets no
control: the selector immediately above already makes that obvious.

Unticking the chosen term does not clear the meta. The choice is ignored while
the term is missing and honoured again the moment it is ticked back on.

## Not here yet

Documented so the shape is clear, but landing in later work:

- **The Yoast migration command.** The meta key deliberately mirrors Yoast SEO's
  `_yoast_wpseo_primary_{$taxonomy}`, so the migration is a key rename rather
  than a data transform.

## Development

```bash
composer install
npm install

composer lint   # PHPCS, HM standard
npm test        # PHPUnit inside WordPress Playground — no database needed
```

The integration suite runs `WP_UnitTestCase` against the ephemeral,
SQLite-backed WordPress that `@wp-playground/cli` boots, so there is no MySQL or
Docker to set up. See `tests/integration/bootstrap-playground.php` for the one
shim that makes that work.

## Origin

Generalised from the primary-sector feature built for The Media Leader, where it
is in production.

## Licence

GPL-2.0-or-later.
