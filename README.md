# HM Primary Category

Lets an editor mark one term per taxonomy as a post's **primary** one, and gives
you explicit ways to use that choice: an API, an option on core's Post Terms
block, a Block Bindings source, and `%category%` permalinks.

Nothing about a post's term order changes. `get_the_terms()`, `get_the_category()`
and everything built on them return exactly what they returned before — the
plugin stores the choice and hands it to whatever asks for it.

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
when the post meta is registered. A taxonomy added after that has no meta, and
so no REST field, no editor picker and no block toggle.

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
```

The choice is stored in post meta keyed `_hm_primary_{$taxonomy}`, registered
for every post type the taxonomy is attached to and exposed in the REST API.

## The Post Terms block

Core's Post Terms block takes an extra `hmPrimaryOnly` attribute. Set it and the
block renders the post's primary term for whichever taxonomy its own `term`
attribute names, instead of the whole list:

```html
<!-- wp:post-terms {"term":"category","hmPrimaryOnly":true} /-->
```

In the editor the option is a **Primary term only** toggle in the block's
inspector sidebar. It appears only when the block points at a taxonomy this
plugin has enabled, so the toggle cannot promise something the server will not
deliver.

Markup, classes, prefix and suffix are core's — the block renders normally and
the list is trimmed to its first term, rather than being rebuilt by hand.

A taxonomy that is *not* enabled still works if you set the attribute directly:
it falls back to that taxonomy's first term rather than erroring.

The term ordering this needs is applied for the duration of that one block's
render and removed immediately afterwards. No other block, template or query in
the same request sees a changed term order.

## The block binding

The Post Terms block renders the primary term as a *term list*. When you want
the bare value — in a heading, a button's URL, a sentence — bind it instead. The
`hm/primary-term` source resolves to the same term `get_primary_term()` returns,
for any block attribute that accepts a binding.

| Argument | Values | Default |
| --- | --- | --- |
| `taxonomy` | Any taxonomy name. | `category` |
| `key` | `name`, `url` or `slug`. | none — required |

`url` is the term's archive link, via `get_term_link()`, so it works for any
taxonomy rather than just categories.

A "More on ..." button linking to the primary sector's archive, with the label
bound to the sector's name:

```html
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{
	"url":{"source":"hm/primary-term","args":{"taxonomy":"sector","key":"url"}},
	"text":{"source":"hm/primary-term","args":{"taxonomy":"sector","key":"name"}}
}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">More</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
```

And the primary category's name in a paragraph:

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"hm/primary-term","args":{"key":"name"}}}}} -->
<p>Uncategorised</p>
<!-- /wp:paragraph -->
```

The binding returns nothing — and so the block keeps its own saved content — when
the post has no terms in that taxonomy, when `key` is not one of the three above,
or when the term has no resolvable link. That fallback is the point: write
something sensible into the block and an uncategorised post renders it, rather
than an empty heading or a dead link.

There is deliberately **no format or template argument**. A translatable string
like `"More on %s"` parked in block markup is out of reach of the site's own
translations — wrap the binding in whatever markup and copy the template needs
instead.

The source requires WordPress 6.5+ for Block Bindings. On anything older it is
not registered and every bound block falls back to its own content.

## Permalinks

Sites with `%category%` in their permalink structure get the primary category in
the URL rather than whichever one core happened to pick. This is a
`post_link_category` filter, and it applies only while `category` is an enabled
taxonomy and the primary is one of the categories core offered.

Changing which category appears in a post's URL changes that URL. On an existing
site, check your redirects before enabling this on a `%category%` structure.

Turn it off on a site that already has `%category%` URLs in the wild —
changing which term appears changes the URL:

```php
add_filter( 'hm_primary_term_filter_permalinks', '__return_false' );
```

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

## Upgrading to 0.2.0

**0.2.0 removes the global `get_the_terms` filter.** Up to 0.1.2 the plugin put
the chosen term at index 0 for every caller. That reach was the selling point,
and it is the reason it has gone: it changed feeds and archive listings that
never asked for it, and a maintainer reading `get_the_category()[0]` had no way
to see why it worked.

What replaces it is explicit and opt-in: `get_primary_term()`, the
`hmPrimaryOnly` block attribute, and the permalink filter.

If you were relying on the ordering, you have to say so now:

| Was | Now |
| --- | --- |
| `get_the_category( $post )[0]` | `get_primary_term( $post )` |
| `get_the_terms( $post, $tax )[0]` | `get_primary_term( $post, $tax )` |
| A `core/post-terms` block showing the primary first | Add `hmPrimaryOnly` — it now shows the primary alone |
| `%category%` permalinks picking the primary | Still does, now via `post_link_category` rather than as a side effect |

`sort_primary_term_first()` has been removed. Nothing else in the API changed:
the meta keys, `get_primary_term()`, `set_primary_term()` and the migration
command all behave as before, so there is no data to migrate.

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
