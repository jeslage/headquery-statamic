<?php

namespace Headquery\Statamic\Assets;

/**
 * For projects that bind their own asset class: adds the keys registered with
 * {@see ShallowAsset::extend()} to its shallow keys.
 */
trait HasExtendedShallowKeys
{
    public function shallowAugmentedArrayKeys()
    {
        return array_values(array_unique([...parent::shallowAugmentedArrayKeys(), ...ShallowAsset::keys()]));
    }
}
