<?php

namespace Headquery\Statamic\Assets;

use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Contracts\Assets\Asset as AssetContract;

/**
 * Adds keys to the shallow asset Statamic returns for asset fields and asset links in the
 * REST API (by default only `id url permalink api_url alt`), e.g. for an image loader:
 *
 *     // AppServiceProvider::register()
 *     ShallowAsset::extend(['width', 'height', 'focus_css']);
 *
 * This binds Statamic's asset contract to {@see Asset}. A project that already binds its own
 * asset class keeps it and adds the {@see HasExtendedShallowKeys} trait to it instead.
 * The schema export reads the effective keys, so codegen types them on `HqAsset`.
 */
class ShallowAsset
{
    /** Statamic's own shallow keys (`Statamic\Assets\Asset::shallowAugmentedArrayKeys()`). */
    public const DEFAULT_KEYS = ['id', 'url', 'permalink', 'api_url', 'alt'];

    /** @var list<string> */
    protected static array $keys = [];

    /** @param  list<string>  $keys  Keys of the augmented asset, e.g. `width` or a blueprint field. */
    public static function extend(array $keys): void
    {
        static::$keys = array_values(array_unique([...static::$keys, ...array_map('strval', $keys)]));

        static::bind();
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return static::$keys;
    }

    public static function flush(): void
    {
        static::$keys = [];
    }

    /**
     * Bind the contract to headquery's asset class unless the project bound its own.
     * Called again on boot, in case extend() ran before Statamic registered its binding.
     */
    public static function bind(): void
    {
        if (static::$keys === [] || ! function_exists('app')) {
            return;
        }

        $app = app();
        if ($app->bound(AssetContract::class) && get_class($app->make(AssetContract::class)) !== StatamicAsset::class) {
            return;
        }

        $app->bind(AssetContract::class, Asset::class);
    }
}
