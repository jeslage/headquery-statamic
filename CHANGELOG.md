# @headquery-internal/php-statamic

## 0.5.0

### Minor Changes

- c35cf6c: Add `ShallowAsset::extend([...])` (and the `HasExtendedShallowKeys` trait for custom asset classes) to add keys to the shallow assets the REST API returns for asset fields and links. The schema export lists the extra keys as `assetKeys`.

## 0.4.0

### Minor Changes

- 92321cb: Export Statamic link fields as the new IR type `link` (with `targets`) instead of `string`.
