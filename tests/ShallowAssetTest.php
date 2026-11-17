<?php

namespace Headquery\Statamic\Tests;

use Headquery\Statamic\Assets\HasExtendedShallowKeys;
use Headquery\Statamic\Assets\ShallowAsset;
use PHPUnit\Framework\TestCase;

class FakeAsset
{
    public function shallowAugmentedArrayKeys()
    {
        return ShallowAsset::DEFAULT_KEYS;
    }
}

class FakeExtendedAsset extends FakeAsset
{
    use HasExtendedShallowKeys;
}

class ShallowAssetTest extends TestCase
{
    protected function tearDown(): void
    {
        ShallowAsset::flush();
    }

    public function test_no_keys_by_default(): void
    {
        $this->assertSame([], ShallowAsset::keys());
        $this->assertSame(ShallowAsset::DEFAULT_KEYS, (new FakeExtendedAsset)->shallowAugmentedArrayKeys());
    }

    public function test_extend_merges_and_dedupes_in_order(): void
    {
        ShallowAsset::extend(['width', 'height']);
        ShallowAsset::extend(['height', 'focus_css']);

        $this->assertSame(['width', 'height', 'focus_css'], ShallowAsset::keys());
    }

    public function test_trait_appends_keys_without_duplicating_defaults(): void
    {
        ShallowAsset::extend(['width', 'alt', 'focus_css']);

        $this->assertSame(
            [...ShallowAsset::DEFAULT_KEYS, 'width', 'focus_css'],
            (new FakeExtendedAsset)->shallowAugmentedArrayKeys(),
        );
    }

    public function test_flush(): void
    {
        ShallowAsset::extend(['width']);
        ShallowAsset::flush();

        $this->assertSame([], ShallowAsset::keys());
    }
}
