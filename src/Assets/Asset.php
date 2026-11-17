<?php

namespace Headquery\Statamic\Assets;

use Statamic\Assets\Asset as StatamicAsset;

/** Statamic's asset with the shallow keys from {@see ShallowAsset::extend()}. */
class Asset extends StatamicAsset
{
    use HasExtendedShallowKeys;
}
