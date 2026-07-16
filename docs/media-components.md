# Media Components

Shared image framing is rendered through
`public/views/partials/media-frame.html`.

Styling lives in `public/styles/13-media-components.css` and crop math lives in
`public/scripts/media-frame.js`.

The goal is to unify how a saved crop is interpreted, not to share crop values.
Each character and each story keeps its own fit, focus and zoom values.

## Base Template

Use this PHP template for any image that should participate in the shared crop
system:

```php
<?php oc_render_media_frame([
    'variant' => 'character-card',
    'mode' => $imageDisplayMode,
    'src' => '/public/uploads/' . $image,
    'alt' => 'Postac',
    'fit' => $imageFit,
    'focusX' => $imageFocusX,
    'focusY' => $imageFocusY,
    'zoom' => $imageZoom,
]); ?>
```

The template owns the HTML structure and class list. Views should pass values
and variant names, not recreate `oc-media-frame` markup by hand.

Custom content inside the frame, such as card buttons, goes into the `after`
slot:

```php
<?php oc_render_media_frame([
    'variant' => 'character-card',
    'src' => $src,
    'alt' => $alt,
    'after' => function () {
        ?><button class="card-edit-btn">...</button><?php
    },
]); ?>
```

## Tile Body

Character and story cards should use the same body renderer:

```php
<?php oc_render_tile_body([
    'class' => 'card-content',
    'title' => $title,
    'description' => $description,
    'filters' => $filters,
    'filterTitle' => $filterTitle,
    'maxFilters' => 4,
]); ?>
```

Descriptions are visually clamped to 8 lines in
`public/styles/13-media-components.css`. Do not truncate card descriptions in
PHP unless the data itself must be shortened.

## Variants

`portrait`

For the reference character portrait used in edit and view screens. It maps to
`oc-media-frame--portrait character-main-image-frame`.

`character-card`

For character tiles. It maps to
`oc-media-frame--character-card card-image-wrapper`.

`story-card`

For story tiles and story tile previews. It maps to
`oc-media-frame--story-card story-preview-cover`.

`story-reader`

For wide story reader covers. It maps to
`oc-media-frame--story-reader story-reader-cover`.

## Rule

If a new reusable portrait, cover, card image, avatar, gallery thumbnail, or
reader image is added, ask whether it should be unified before adding custom
markup/CSS. If yes, add or reuse a variant in `media-frame.html` and style it in
`13-media-components.css`.

## Image Upload Controls

Shared image picking/upload controls live in
`public/views/partials/media-upload.html`.

Use:

```php
<?php oc_render_image_upload_controls([
    'previewSelector' => '#preview-img',
    'filenameSelector' => '#image-filename',
    'imageIdSelector' => '#image-id',
]); ?>
```

The default label is `Wybierz zdjęcie`. The button opens `OCImageTools`, which
can pick from the gallery or upload a new image with required filters. Do not add
plain user-facing `input type="file"` controls for uploaded images unless there
is also an explicit tag/filter flow.
