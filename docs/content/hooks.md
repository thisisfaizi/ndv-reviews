# Hooks & Filters

The free plugin exposes a stable internal API that the Pro add-on (and third parties) extend. Pro **never** edits free files.

## Actions

| Hook | Fired | Args |
|---|---|---|
| `ndv-reviews/loaded` | After the free plugin finishes booting (WooCommerce active). The Pro add-on boots here, checks its license, and registers modules. | `NdvReviews\Plugin $plugin` |

## Filters

| Hook | Purpose | Args |
|---|---|---|
| `ndv-reviews/services` | Add/replace core service modules (each implements `Registerable`) before their hooks register. | `Registerable[] $services`, `NdvReviews\Plugin $plugin` |

## The service container

`$plugin->container()` returns the shared `Container`. Register a lazy service:

```
add_action( 'ndv-reviews/loaded', function ( $plugin ) {
    $plugin->container()->set( 'my_service', function ( $c ) {
        return new My_Service( $c->get( 'settings' ) );
    } );
} );
```

Core-bound services: `settings` → `NdvReviews\Support\Settings`.

## Conventions

- Action/filter names use the `ndv-reviews/` namespace prefix.
- Every documented hook lists its arguments; new hooks are added here as phases land.

> More hooks (review lifecycle, request scheduling, schema output, display filters) are documented as Phases 1–4 introduce them.
