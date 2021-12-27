<?php

declare(strict_types=1);

/*
 * This file is part of Gallery Creator Bundle.
 *
 * (c) Marko Cupic 2021 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/gallery-creator-bundle
 */

/*
 * Legends
 */
$GLOBALS['TL_LANG']['tl_content']['pagination_legend'] = 'Pagination settings';
$GLOBALS['TL_LANG']['tl_content']['miscellaneous_legend'] = 'Miscellaneous settings';
$GLOBALS['TL_LANG']['tl_content']['album_listing_legend'] = 'Album listing legend';
$GLOBALS['TL_LANG']['tl_content']['picture_listing_legend'] = 'Picture listing legend';

/*
 * Fields
 */
$GLOBALS['TL_LANG']['tl_content']['gcPublishAlbums'] = ['Select one or more albums', 'Selected albums will be displayed in the frontend.'];
$GLOBALS['TL_LANG']['tl_content']['gcPublishSingleAlbum'] = ['Select one album', 'The selected album will be displayed in the frontend.'];
$GLOBALS['TL_LANG']['tl_content']['gcPublishAllAlbums'] = ['Publish all given albums', 'Publish all albums on the front end.'];
$GLOBALS['TL_LANG']['tl_content']['gcShowChildAlbums'] = ['Show child albums', 'Show child albums too.'];
$GLOBALS['TL_LANG']['tl_content']['gcRedirectSingleAlb'] = ['Redirection in case of a single album', 'Should be automatically redirected to the detail-view, in case of single-album-choice?'];
$GLOBALS['TL_LANG']['tl_content']['gcAlbumsPerPage'] = ['Items per page in the album listing', 'The number of items per page in the album listing. Set to 0 to disable pagination.'];
$GLOBALS['TL_LANG']['tl_content']['gcThumbsPerPage'] = ['Thumbs per page in the detail view', 'The number of thumbnails per page in the detail view. Set to 0 to disable pagination.'];
$GLOBALS['TL_LANG']['tl_content']['gcSorting'] = ['Album sorting', 'According to which field the albums should be sorted?'];
$GLOBALS['TL_LANG']['tl_content']['gcSortingDirection'] = ['Sort sequence', 'DESC: descending, ASC: ascending'];
$GLOBALS['TL_LANG']['tl_content']['gcPictureSorting'] = ['Picture sorting', 'The pictures will be sorted according to the selected field.'];
$GLOBALS['TL_LANG']['tl_content']['gcPictureSortingDirection'] = ['Sort sequence', 'DESC: descending, ASC: ascending'];
$GLOBALS['TL_LANG']['tl_content']['gcSizeDetailView'] = ['Detail view: Thumbnail width and height', 'Here you can set the image dimensions and the resize mode.'];
$GLOBALS['TL_LANG']['tl_content']['gcSizeAlbumListing'] = ['Album list: Thumbnail width and height', 'Here you can set the image dimensions and the resize mode.'];
$GLOBALS['TL_LANG']['tl_content']['gcFullSize'] = ['Full-size view/new window', 'Open the full-size image in a lightbox or in a new browser window.'];

/*
 * References
 */
$GLOBALS['TL_LANG']['tl_content']['gcSortingDirection']['DESC'] = 'Ascending';
$GLOBALS['TL_LANG']['tl_content']['gcSortingDirection']['ASC'] = 'Descending';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['sorting'] = 'Backend-module sorting (sorting)';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['id'] = 'ID';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['date'] = 'Date of creation (date)';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['name'] = 'Name (name)';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['owner'] = 'Owner (owner)';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['caption'] = 'Caption (caption)';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['title'] = 'Image-title (title)';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['tstamp'] = 'Revision date (tstamp)';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['alias'] = 'Album alias (alias)';
$GLOBALS['TL_LANG']['tl_content']['gcSortingField']['visitors'] = 'Number of visitors (visitors)';
