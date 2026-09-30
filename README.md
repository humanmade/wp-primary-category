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

It appears only once the post has two or more terms in that taxonomy. One term
is the primary by definition, so a control offering a single fixed option is a
question with one answer. Assign a second term and it appears.

It appears for every enabled taxonomy, hierarchical or flat, on every post type
that taxonomy is attached to. A post with no terms in the taxonomy gets no
control: the selector immediately above already makes that obvious.

Unticking the chosen term does not clear the meta. The choice is ignored while
the term is missing and honoured again the moment it is ticked back on.

## Migrating from Yoast SEO

Yoast stores a primary term as a term ID in `_yoast_wpseo_primary_{$taxonomy}`.
This plugin stores the same integer in `_hm_primary_{$taxonomy}`. The shapes are
identical, so the migration copies keys rather than transforming data:

```bash
wp primary-term migrate
```

Reversing the direction syncs choices back to Yoast, which is what you want
before rolling this plugin back:

```bash
wp primary-term migrate --from=hm --to=yoast --overwrite
```

That is a one-off command and deliberately not a runtime hook, so nothing in
this plugin depends on Yoast being installed.

### Flags

| Flag | What it does |
| --- | --- |
| `--from=<yoast\|hm>` | Where to read from. Default `yoast`. |
| `--to=<yoast\|hm>` | Where to write. Default `hm`. Must differ from `--from`. |
| `--taxonomy=<list>` | Comma-separated. Defaults to the enabled taxonomies — pass this explicitly for one that is not enabled yet. |
| `--post-type=<list>` | Comma-separated. Defaults to every post type each taxonomy is attached to. |
| `--batch-size=<n>` | Posts loaded per batch. Default 500. |
| `--dry-run` | Report and write nothing, `--cleanup` included. |
| `--overwrite` | Replace a destination that already holds a different term. |
| `--cleanup` | Delete the source meta once a post has been copied successfully. |

Every post is reported as one of five outcomes, counted per taxonomy:

- **copied** — the destination was empty and the source had a usable value.
- **already** — the destination already held the same term. Nothing written.
- **conflict** — the destination held a *different* term. Skipped and logged
  with its post ID, because that value is somebody's explicit choice and you did
  not ask for it to be thrown away. `--overwrite` replaces it and counts it as
  copied.
- **orphaned** — the source names a term the post no longer has, or one that no
  longer exists. Skipped and logged: `get_primary_term()` would refuse to return
  it, so copying it would only spread a value that is already broken.
- **empty** — no source value. Skipped quietly.

Conflicts and orphans end the run on `WP_CLI::warning()` rather than
`WP_CLI::success()`, so a scripted run can tell that something was left behind.

`--cleanup` deletes the source only after a successful copy. Never on a skip, an
unresolved conflict, or a dry run.

### A worked upgrade path

```bash
# 1. See what would happen. Nothing is written.
wp primary-term migrate --dry-run

# 2. Do it. Conflicts are reported with their post IDs and left alone.
wp primary-term migrate

# 3. Verify: a second dry run should now report everything as `already`.
wp primary-term migrate --dry-run

# 4. Once you are happy, drop Yoast's copy of the data.
wp primary-term migrate --cleanup
```

Deal with any conflicts between steps 2 and 4 — either by hand, or by re-running
with `--overwrite` if Yoast's value is the one you want to keep. Step 4 will not
clean up a post it has not successfully copied, so anything unresolved keeps
both values until you decide.

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
