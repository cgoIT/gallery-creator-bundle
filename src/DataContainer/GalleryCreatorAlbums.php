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

namespace Markocupic\GalleryCreatorBundle\DataContainer;

use Contao\Automator;
use Contao\Backend;
use Contao\BackendTemplate;
use Contao\Config;
use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\ServiceAnnotation\Callback;
use Contao\DataContainer;
use Contao\Date;
use Contao\Dbafs;
use Contao\File;
use Contao\FilesModel;
use Contao\FileUpload;
use Contao\Folder;
use Contao\Image;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DoctrineDBALException;
use Markocupic\GalleryCreatorBundle\Model\GalleryCreatorAlbumsModel;
use Markocupic\GalleryCreatorBundle\Model\GalleryCreatorPicturesModel;
use Markocupic\GalleryCreatorBundle\Revise\ReviseAlbumDatabase;
use Markocupic\GalleryCreatorBundle\Util\AlbumUtil;
use Markocupic\GalleryCreatorBundle\Util\FileUtil;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class GalleryCreatorAlbums.
 */
class GalleryCreatorAlbums extends Backend
{
    private RequestStack $requestStack;

    private AlbumUtil $albumUtil;

    private FileUtil$fileUtil;

    private Connection $connection;

    private ReviseAlbumDatabase $reviseAlbumDatabase;

    private string $projectDir;

    private string $galleryCreatorUploadPath;

    private bool $galleryCreatorBackendWriteProtection;

    private array $galleryCreatorValidExtensions;

    private bool $restrictedUser = false;

    public function __construct(RequestStack $requestStack, AlbumUtil $albumUtil, FileUtil $fileUtil, Connection $connection, ReviseAlbumDatabase $reviseAlbumDatabase, string $projectDir, string $galleryCreatorUploadPath, bool $galleryCreatorBackendWriteProtection, array $galleryCreatorValidExtensions)
    {
        $this->requestStack = $requestStack;
        $this->albumUtil = $albumUtil;
        $this->fileUtil = $fileUtil;
        $this->connection = $connection;
        $this->reviseAlbumDatabase = $reviseAlbumDatabase;
        $this->projectDir = $projectDir;
        $this->galleryCreatorUploadPath = $galleryCreatorUploadPath;
        $this->galleryCreatorBackendWriteProtection = $galleryCreatorBackendWriteProtection;
        $this->galleryCreatorValidExtensions = $galleryCreatorValidExtensions;

        parent::__construct();

        $this->import('BackendUser', 'User');

        $session = $this->requestStack->getCurrentRequest()->getSession();
        $bag = $session->getBag('contao_backend');

        if (isset($bag['CLIPBOARD']['tl_gallery_creator_albums']['mode'])) {
            if ('copyAll' === $bag['CLIPBOARD']['tl_gallery_creator_albums']['mode']) {
                $this->redirect('contao?do=gallery_creator&clipboard=1');
            }
        }
    }

    /**
     * Button callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="list.operations.uploadImages.button")
     */
    public function buttonCbUploadImages(array $row, ?string $href, string $label, string $title, ?string $icon, string $attributes): string
    {
        $href = sprintf($href, $row['id']);

        return sprintf(
            '<a href="%s" title="%s"%s>%s</a> ',
            $this->addToUrl($href),
            StringUtil::specialchars($title),
            $attributes,
            Image::getHtml($icon, $label),
        );
    }

    /**
     * Check Permission callback.
     */
    public function checkPermissionCbToggle(string $table, array $hasteAjaxOperationSettings, bool &$hasPermission): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $hasPermission = true;

        if ($request->request->has('id')) {
            $id = (int) $request->request->get('id', 0);
            $album = $this->connection->fetchAssociative('SELECT * FROM tl_gallery_creator_albums WHERE id = ?', [$id]);

            if ($album) {
                if (!$this->User->isAdmin && (int) $album['owner'] !== (int) $this->User->id && $this->galleryCreatorBackendWriteProtection) {
                    $hasPermission = false;
                    Message::addError(sprintf($GLOBALS['TL_LANG']['ERR']['rejectWriteAccessToAlbum'], $album['name']));
                    $this->redirect('contao?do=gallery_creator');
                }
            }
        }
    }

    /**
     * Button callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="list.operations.cutPicture.button")
     */
    public function buttonCbCutPicture(array $row, ?string $href, string $label, string $title, ?string $icon, string $attributes): string
    {
        $href .= '&id='.$row['id'];

        if ($this->User->isAdmin || (int) $this->User->id === (int) $row['owner'] || !$this->galleryCreatorBackendWriteProtection) {
            return sprintf(
                '<a href="%s" title="%s"%s>%s</a> ',
                $this->addToUrl($href),
                StringUtil::specialchars($title),
                $attributes,
                Image::getHtml($icon, $label),
            );
        }

        return Image::getHtml(preg_replace('/\.svg$/i', '_.svg', $icon)).' ';
    }

    /**
     * Paste button callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="list.sorting.paste_button")
     *
     * @param $arrClipboard
     */
    public function buttonCbPastePicture(DataContainer $dc, array $row, string $table, bool $cr, $arrClipboard = false): string
    {
        $disablePA = false;
        $disablePI = false;

        // Disable all buttons if there is a circular reference
        if ($this->User->isAdmin && false !== $arrClipboard && ('cut' === $arrClipboard['mode'] && (1 === (int) $cr || (int) $arrClipboard['id'] === (int) $row['id']) || 'cutAll' === $arrClipboard['mode'] && (1 === (int) $cr || \in_array($row['id'], $arrClipboard['id'], false)))) {
            $disablePA = true;
            $disablePI = true;
        }

        // Return the buttons
        $imagePasteAfter = Image::getHtml('pasteafter.svg', sprintf($GLOBALS['TL_LANG'][$table]['pasteafter'][1], $row['id']), 'class="blink"');
        $imagePasteInto = Image::getHtml('pasteinto.svg', sprintf($GLOBALS['TL_LANG'][$table]['pasteinto'][1], $row['id']), 'class="blink"');

        $return = '';

        if ($row['id'] > 0) {
            $return = $disablePA ? Image::getHtml('pasteafter_.svg', '', 'class="blink"').' ' : '<a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&mode=1&pid='.$row['id'].(!\is_array($arrClipboard['id']) ? '&id='.$arrClipboard['id'] : '')).'" title="'.StringUtil::specialchars(sprintf($GLOBALS['TL_LANG'][$table]['pasteafter'][1], $row['id'])).'" onclick="Backend.getScrollOffset();">'.$imagePasteAfter.'</a> ';
        }

        return $return.($disablePI ? Image::getHtml('pasteinto_.svg', '', 'class="blink"').' ' : '<a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&mode=2&pid='.$row['id'].(!\is_array($arrClipboard['id']) ? '&id='.$arrClipboard['id'] : '')).'" title="'.StringUtil::specialchars(sprintf($GLOBALS['TL_LANG'][$table]['pasteinto'][1], $row['id'])).'" onclick="Backend.getScrollOffset();">'.$imagePasteInto.'</a> ');
    }

    /**
     * Button callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="list.operations.delete.button")
     */
    public function buttonCbDelete(array $row, ?string $href, string $label, string $title, ?string $icon, string $attributes): string
    {
        $href .= '&id='.$row['id'];

        if ($this->User->isAdmin || (int) $this->User->id === (int) $row['owner'] || !$this->galleryCreatorBackendWriteProtection) {
            return sprintf(
                '<a href="%s" title="%s"%s>%s</a> ',
                $this->addToUrl($href),
                StringUtil::specialchars($title),
                $attributes,
                Image::getHtml($icon, $label),
            );
        }

        return Image::getHtml(preg_replace('/\.svg$/i', '_.svg', $icon)).' ';
    }

    /**
     * Button callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="list.operations.importImagesFromFilesystem.button")
     */
    public function buttonCbImportImages(array $row, ?string $href, string $label, string $title, ?string $icon, string $attributes): string
    {
        $href = sprintf($href, $row['id']);

        return sprintf(
            '<a href="%s" title="%s"%s>%s</a> ',
            $this->addToUrl($href),
            StringUtil::specialchars($title),
            $attributes,
            Image::getHtml($icon, $label),
        );
    }

    /**
     * Input field callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.reviseDatabase.input_field")
     */
    public function inputFieldCbReviseDatabase(): string
    {
        $translator = System::getContainer()->get('translator');
        $twig = System::getContainer()->get('twig');

        return (new Response(
            $twig->render(
                '@MarkocupicGalleryCreator/Backend/revise_database.html.twig',
                [
                    'trans' => [
                        'albums_messages_reviseDatabase' => [
                            $translator->trans('tl_gallery_creator_albums.reviseDatabase.0', [], 'contao_default'),
                            $translator->trans('tl_gallery_creator_albums.reviseDatabase.1', [], 'contao_default'),
                        ],
                    ],
                ]
            )
        ))->getContent();
    }

    /**
     * Input field callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.albumInfo.input_field")
     */
    public function inputFieldCbAlbumInfo(DataContainer $dc): string
    {
        if (null === ($objAlb = GalleryCreatorAlbumsModel::findByPk($dc->activeRecord->id))) {
            return '';
        }

        $objUser = UserModel::findByPk($objAlb->owner);
        $objAlb->ownersName = null !== $objUser ? $objUser->name : 'no-name';
        $objAlb->date_formatted = Date::parse('Y-m-d', $objAlb->date);

        // Check User Role
        $this->checkUserRole($dc->activeRecord->id);

        $translator = System::getContainer()->get('translator');
        $twig = System::getContainer()->get('twig');

        return (new Response(
            $twig->render(
                '@MarkocupicGalleryCreator/Backend/album_information.html.twig',
                [
                    'restricted' => $this->restrictedUser,
                    'model' => $objAlb->row(),
                    'trans' => [
                        'album_id' => $translator->trans('tl_gallery_creator_albums.id.0', [], 'contao_default'),
                        'album_name' => $translator->trans('tl_gallery_creator_albums.name.0', [], 'contao_default'),
                        'album_date' => $translator->trans('tl_gallery_creator_albums.date.0', [], 'contao_default'),
                        'album_owners_name' => $translator->trans('tl_gallery_creator_albums.owner.0', [], 'contao_default'),
                        'album_caption' => $translator->trans('tl_gallery_creator_albums.caption.0', [], 'contao_default'),
                        'album_thumb' => $translator->trans('tl_gallery_creator_albums.thumb.0', [], 'contao_default'),
                    ],
                ]
            )
        ))->getContent();
    }

    /**
     * Input field callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.fileupload.input_field")
     */
    public function inputFieldCbFileUpload(): string
    {
        // Create the template object
        $objTemplate = new BackendTemplate('be_gc_uploader');

        // Maximum uploaded size
        $objTemplate->maxUploadedSize = FileUpload::getMaxUploadSize();

        // Allowed extensions
        $objTemplate->strAccepted = implode(',', array_map(static fn ($el) => '.'.$el, $this->galleryCreatorValidExtensions));

        // $_FILES['file']
        $objTemplate->strName = 'file';

        // Return the parsed uploader template
        return $objTemplate->parse();
    }

    /**
     * Onload callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="config.onload", priority=100)
     *
     * @throws DoctrineDBALException
     */
    public function onloadCbHandleAjax(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request->getSession();

        if ($request->query->has('isAjaxRequest')) {
            // Change sorting value
            if ($request->query->has('pictureSorting')) {
                $sorting = 10;

                foreach (explode(',', $request->query->get('pictureSorting')) as $pictureId) {
                    $objPicture = GalleryCreatorPicturesModel::findByPk($pictureId);

                    if (null !== $objPicture) {
                        $objPicture->sorting = $sorting;
                        $objPicture->save();
                        $sorting += 10;
                    }
                }
            }

            // Revise table in the backend
            if ($request->query->has('checkTables')) {
                if ($request->query->has('getAlbumIDS')) {
                    $arrIDS = $this->connection->fetchFirstColumn('SELECT id FROM tl_gallery_creator_albums ORDER BY RAND()');

                    throw new ResponseException(new JsonResponse(['ids' => $arrIDS]));
                }

                if ($request->query->has('albumId')) {
                    $objAlbum = GalleryCreatorAlbumsModel::findByPk($request->query->get('albumId', 0));

                    if (null !== $objAlbum) {
                        if ($request->query->has('checkTables') || $request->query->has('reviseTables')) {
                            // Delete damaged data records
                            $cleanDb = $this->User->isAdmin && $request->query->has('reviseTables') ? true : false;

                            $this->reviseAlbumDatabase->run($objAlbum, $cleanDb);

                            if ($session->has('gc_error') && \is_array($session->get('gc_error'))) {
                                if (!empty($session->get('gc_error'))) {
                                    $arrErrors = $session->get('gc_error');

                                    if (!empty($arrErrors)) {
                                        throw new ResponseException(new JsonResponse(['errors' => $arrErrors]));
                                    }
                                }
                            }
                        }
                    }
                    $session->remove('gc_error');
                }
            }

            throw new ResponseException(new Response('', Response::HTTP_NO_CONTENT));
        }
    }

    /**
     * Label callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="list.label.label")
     */
    public function labelCb(array $row, string $label): string
    {
        $countImages = $this->connection->fetchOne('SELECT count(id) as countImg FROM tl_gallery_creator_pictures WHERE pid = ?', [$row['id']]);

        $label = str_replace('#count_pics#', $countImages, $label);
        $label = str_replace('#datum#', Date::parse(Config::get('dateFormat'), $row['date']), $label);
        $image = $row['published'] ? 'album.svg' : '_album.svg';
        $label = str_replace('#icon#', $image, $label);
        $href = sprintf('contao?do=gallery_creator&table=tl_gallery_creator_albums&id=%s&act=edit&rt=%s&ref=%s', $row['id'], REQUEST_TOKEN, TL_REFERER_ID);
        $label = str_replace('#href#', $href, $label);
        $label = str_replace('#title#', sprintf($GLOBALS['TL_LANG']['tl_gallery_creator_albums']['edit_album'][1], $row['id']), $label);

        return $label;
    }

    /**
     * Load callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.imageQuality.load")
     */
    public function loadCbImageQuality(): string
    {
        return $this->User->gcImageQuality;
    }

    /**
     * Load callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.imageResolution.load")
     */
    public function loadCbImageResolution(): string
    {
        return $this->User->gcImageResolution;
    }

    /**
     * Buttons callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="edit.buttons")
     */
    public function buttonsCallback(array $arrButtons, DataContainer $dc): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if ('reviseDatabase' === $request->query->get('key')) {
            // Remove buttons
            unset($arrButtons['saveNcreate'], $arrButtons['saveNclose']);

            $arrButtons['save'] = '<button type="submit" name="save" id="reviseTableBtn" class="tl_submit" accesskey="s">'.$GLOBALS['TL_LANG']['tl_gallery_creator_albums']['reviseTablesBtn'][0].'</button>';
        }

        if ('fileupload' === $request->query->get('key')) {
            // Remove buttons
            unset($arrButtons['save'], $arrButtons['saveNclose'], $arrButtons['saveNcreate']);
        }

        if ('importImagesFromFilesystem' === $request->query->get('key')) {
            // Remove buttons
            unset($arrButtons['saveNclose'], $arrButtons['saveNcreate'], $arrButtons['uploadNback']);
        }

        return $arrButtons;
    }

    /**
     * Ondelete callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="config.ondelete", priority=100)
     */
    public function ondeleteCb(DataContainer $dc): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if ('deleteAll' !== $request->query->get('act') && $dc->activeRecord->id > 0) {
            $this->checkUserRole($dc->activeRecord->id);

            if ($this->restrictedUser) {
                $this->log('An unauthorized user tried to delete an entry from tl_gallery_creator_albums with ID '.$dc->activeRecord->id.'.', __METHOD__, TL_ERROR);
                $this->redirect('contao?do=error');
            }
            // Also delete the child element
            $arrDeletedAlbums = GalleryCreatorAlbumsModel::getChildAlbums($dc->activeRecord->id);
            $arrDeletedAlbums = array_merge([$dc->activeRecord->id], $arrDeletedAlbums);

            foreach ($arrDeletedAlbums as $idDelAlbum) {
                $objAlbumModel = GalleryCreatorAlbumsModel::findByPk($idDelAlbum);

                if (null === $objAlbumModel) {
                    continue;
                }

                if ($this->User->isAdmin || (int) $objAlbumModel->owner === (int) $this->User->id || !$this->galleryCreatorBackendWriteProtection) {
                    // Remove all pictures from tl_gallery_creator_pictures
                    $objPicturesModel = GalleryCreatorPicturesModel::findByPid($idDelAlbum);

                    if (null !== $objPicturesModel) {
                        while ($objPicturesModel->next()) {
                            $fileUuid = $objPicturesModel->uuid;
                            $objPicturesModel->delete();
                            $objPicture = GalleryCreatorPicturesModel::findByUuid($fileUuid);

                            if (null === $objPicture) {
                                $oFile = FilesModel::findByUuid($fileUuid);

                                if (null !== $oFile) {
                                    $file = new File($oFile->path);
                                    $file->delete();
                                }
                            }
                        }
                    }

                    // Remove the folder from the database and from the filesystem.
                    $filesModel = FilesModel::findByUuid($objAlbumModel->assignedDir);

                    if (null !== $filesModel) {
                        $folder = new Folder($filesModel->path);

                        // See https://github.com/contao/contao/issues/3854
                        if ($folder->isUnprotected()) {
                            $folder->protect();

                            if ($folder->isEmpty()) {
                                $folder->delete();
                            } else {
                                $folder->unprotect();
                            }
                        } else {
                            if ($folder->isEmpty()) {
                                $folder->delete();
                            }
                        }
                    }
                    $objAlbumModel->delete();
                } else {
                    // Do not delete albums that are not owned by the currently logged-in user.
                    $this->connection->update('tl_gallery_creator_albums', ['pid' => 0], ['id' => $idDelAlbum]);
                }
            }
        }
    }

    /**
     * Onload callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="config.onload", priority=90)
     */
    public function onloadCbCheckFolderSettings(DataContainer $dc): void
    {
        // Create the upload directory if it doesn't already exist
        $objFolder = new Folder($this->galleryCreatorUploadPath);
        $objFolder->unprotect();
        Dbafs::addResource($this->galleryCreatorUploadPath, false);

        $translator = System::getContainer()->get('translator');

        if (!is_writable($this->projectDir.'/'.$this->galleryCreatorUploadPath)) {
            Message::addError($translator->trans('ERR.dirNotWriteable', [$this->galleryCreatorUploadPath], 'contao_default'));
        }
    }

    /**
     * Onload callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="config.onload", priority=80)
     */
    public function onloadCbFileUpload(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if ('fileupload' !== $request->query->get('key') || !$request->query->has('id')) {
            return;
        }

        // Load language file
        $this->loadLanguageFile('tl_files');

        // Album ID
        $intAlbumId = (int) $request->query->get('id');

        // Save uploaded files in $_FILES['file']
        $strName = 'file';

        // Return if there is no album
        if (null === ($objAlbum = GalleryCreatorAlbumsModel::findById($intAlbumId))) {
            Message::addError('Album with ID '.$intAlbumId.' does not exist.');

            return;
        }

        // Return if there is no album directory
        $objUploadDir = FilesModel::findByUuid($objAlbum->assignedDir);

        if (null === $objUploadDir || !is_dir($this->projectDir.'/'.$objUploadDir->path)) {
            Message::addError('No upload directory defined in the album settings!');

            return;
        }

        // Return if there is no upload
        if (!\is_array($_FILES[$strName])) {
            return;
        }

        // Call the uploader script
        $arrUpload = $this->fileUtil->fileupload($objAlbum, $strName);

        foreach ($arrUpload as $strFileSrc) {
            // Add  new data records to tl_gallery_creator_pictures
            $this->fileUtil->addImageToAlbum($objAlbum, $strFileSrc);
        }

        // Do not exit the script if html5_uploader is selected and Javascript is disabled
        if (!$request->request->has('submit')) {
            throw new ResponseException(new Response('', Response::HTTP_NO_CONTENT));
        }
    }

    /**
     * Onload callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="config.onload", priority=70)
     */
    public function onloadCbImportFromFilesystem(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if ('importImagesFromFilesystem' !== $request->query->get('key')) {
            return;
        }
        // Load language file
        $this->loadLanguageFile('tl_content');

        if (!$request->request->get('FORM_SUBMIT')) {
            return;
        }

        if (null !== ($objAlbum = GalleryCreatorAlbumsModel::findByPk($request->query->get('id')))) {
            $objAlbum->preserveFilename = $request->request->get('preserveFilename');
            $objAlbum->save();

            // Comma separated list with folder uuid's => 10585872-5f1f-11e3-858a-0025900957c8,105e9de0-5f1f-11e3-858a-0025900957c8,105e9dd6-5f1f-11e3-858a-0025900957c8
            $arrMultiSRC = explode(',', (string) $request->request->get('multiSRC'));

            if (!empty($arrMultiSRC)) {
                $GLOBALS['TL_DCA']['tl_gallery_creator_albums']['fields']['preserveFilename']['eval']['submitOnChange'] = false;

                // import Images from filesystem and write entries to tl_gallery_creator_pictures
                $this->fileUtil->importFromFilesystem($objAlbum, $arrMultiSRC);

                throw new RedirectResponseException('contao?do=gallery_creator&table=tl_gallery_creator_pictures&id='.$objAlbum->id.'&filesImported=true');
            }
        }

        throw new RedirectResponseException('contao?do=gallery_creator');
    }

    /**
     * Onload callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="config.onload", priority=60)
     */
    public function onloadCbSetUpPalettes(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $dca = &$GLOBALS['TL_DCA']['tl_gallery_creator_albums'];

        // Permit global operations to admin only
        if (!$this->User->isAdmin) {
            unset(
                $dca['list']['global_operations']['all'],
                $dca['list']['global_operations']['reviseDatabase']
            );
        }

        // For security reasons give only readonly rights to these fields
        $dca['fields']['id']['eval']['style'] = '" readonly="readonly';
        $dca['fields']['ownersName']['eval']['style'] = '" readonly="readonly';

        // Create the file uploader palette
        if ('fileupload' === $request->query->get('key')) {
            if ('no_scaling' === $this->User->gcImageResolution) {
                PaletteManipulator::create()
                    ->removeField('imageQuality')
                    ->applyToPalette('fileupload', 'tl_gallery_creator_albums')
                    ;
            }

            $dca['palettes']['default'] = $dca['palettes']['fileupload'];

            return;
        }

        // Create the *importImagesFromFilesystem* palette
        if ('importImagesFromFilesystem' === $request->query->get('key')) {
            $dca['palettes']['default'] = $dca['palettes']['importImagesFromFilesystem'];
            $dca['fields']['preserveFilename']['eval']['submitOnChange'] = false;

            return;
        }

        // The palette for admins
        if ($this->User->isAdmin) {
            // Global operation: revise database
            $albumCount = $this->connection->fetchFirstColumn('SELECT COUNT(id) AS albumCount FROM tl_gallery_creator_albums');
            $albumId = $this->connection->fetchOne('SELECT id FROM tl_gallery_creator_albums');

            if ($albumCount > 0) {
                $dca['list']['global_operations']['reviseDatabase']['href'] = sprintf($dca['list']['global_operations']['reviseDatabase']['href'], $albumId);
            } else {
                unset($dca['list']['global_operations']['reviseDatabase']);
            }

            if ('reviseDatabase' === $request->query->get('key')) {
                $dca['palettes']['default'] = $dca['palettes']['reviseDatabase'];

                return;
            }

            $dca['fields']['owner']['eval']['doNotShow'] = false;
            $dca['fields']['protected']['eval']['doNotShow'] = false;
            $dca['fields']['groups']['eval']['doNotShow'] = false;

            return;
        }
        $id = $request->query->get('id');
        $arrAlb = $this->connection->fetchOne('SELECT * FROM tl_gallery_creator_albums WHERE id = ?', [$id]);

        // Give write access on these fields to admins and album owners only.
        $this->checkUserRole($request->query->get('id'));

        if ($arrAlb['owner'] !== $this->User->id && true === $this->restrictedUser) {
            $dca['palettes']['default'] = $dca['palettes']['restrictedUser'];
        }
    }

    /**
     * Input field callback.
     *
     * List all images of the album (and child albums).
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.thumb.input_field")
     */
    public function inputFieldCbThumb(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === ($objAlbum = GalleryCreatorAlbumsModel::findByPk($request->query->get('id')))) {
            return '';
        }

        // Save input
        if ('tl_gallery_creator_albums' === $request->request->get('FORM_SUBMIT')) {
            if (null === GalleryCreatorPicturesModel::findByPk($request->request->get('thumb'))) {
                $objAlbum->thumb = 0;
            } else {
                $objAlbum->thumb = $request->request->get('thumb');
            }
            $objAlbum->save();
        }

        $arrAlbums = [];
        $arrChildAlbums = [];

        // Generate picture list
        $id = $request->query->get('id');
        $stmt = $this->connection->executeQuery('SELECT * FROM tl_gallery_creator_pictures WHERE pid= ? ORDER BY sorting', [$id]);

        while (false !== ($arrPicture = $stmt->fetchAssociative())) {
            $arrAlbums[] = [
                'uuid' => $arrPicture['uuid'],
                'id' => $arrPicture['id'],
            ];
        }

        // Get all child albums
        $arrChildIds = GalleryCreatorAlbumsModel::getChildAlbums($request->query->get('id'));

        if (!empty($arrChildIds)) {
            $stmt = $this->connection->executeQuery(
                'SELECT * FROM tl_gallery_creator_pictures WHERE pid IN (?) ORDER BY id',
                [$arrChildAlbums],
                [Connection::PARAM_INT_ARRAY]
            );

            while (false !== ($arrPicture = $stmt->fetchAssociative())) {
                $arrChildAlbums[] = [
                    'uuid' => $arrPicture['uuid'],
                    'id' => $arrPicture['id'],
                ];
            }
        }

        $arrContainer = [
            $arrAlbums,
            $arrChildAlbums,
        ];

        foreach ($arrContainer as $i => $arrData) {
            foreach ($arrData as $ii => $arrItem) {
                $objFileModel = FilesModel::findByUuid($arrItem['uuid']);

                if (null !== $objFileModel) {
                    if (file_exists($this->projectDir.'/'.$objFileModel->path)) {
                        $objFile = new \File($objFileModel->path);
                        $src = 'placeholder.png';

                        if ($objFile->height <= Config::get('gdMaxImgHeight') && $objFile->width <= Config::get('gdMaxImgWidth')) {
                            $src = Image::get($objFile->path, 80, 60, 'center_center');
                        }

                        $arrContainer[$i][$ii]['attr_checked'] = $checked = (int) $objAlbum->thumb === (int) $arrItem['id'] ? ' checked' : '';
                        $arrContainer[$i][$ii]['class'] = '' !== \strlen($checked) ? ' class="checked"' : '';
                        $arrContainer[$i][$ii]['filename'] = StringUtil::specialchars($objFile->name);
                        $arrContainer[$i][$ii]['image'] = Image::getHtml($src, $objFile->name);
                    }
                }
            }
        }

        $translator = System::getContainer()->get('translator');
        $twig = System::getContainer()->get('twig');

        return (new Response(
            $twig->render(
                '@MarkocupicGalleryCreator/Backend/album_thumbnail_list.html.twig',
                [
                    'album_thumbs' => $arrContainer[0],
                    'child_album_thumbs' => $arrContainer[1],
                    'has_album_thumbs' => !empty($arrContainer[0]),
                    'has_child_album_thumbs' => !empty($arrContainer[1]),
                    'trans' => [
                        'album_thumb' => $translator->trans('tl_gallery_creator_albums.thumb.0', [], 'contao_default'),
                        'drag_items_hint' => $translator->trans('tl_gallery_creator_albums.thumb.1', [], 'contao_default'),
                        'child_albums' => $translator->trans('GALLERY_CREATOR.childAlbums', [], 'contao_default'),
                    ],
                ]
            )
        ))->getContent();
    }

    /**
     * Save callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.filePrefix.save")
     */
    public function saveCbValidateFilePrefix(string $strPrefix, DataContainer $dc): string
    {
        $i = 0;

        if ('' !== $strPrefix) {
            $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;', \Transliterator::FORWARD);
            $strPrefix = $transliterator->transliterate($strPrefix);
            $strPrefix = str_replace('.', '_', $strPrefix);

            $arrOptions = [
                'column' => ['tl_gallery_creator_pictures.pid = ?'],
                'value' => [$dc->id],
                'order' => 'sorting ASC',
            ];
            $objPicture = GalleryCreatorPicturesModel::findAll($arrOptions);

            if (null !== $objPicture) {
                while ($objPicture->next()) {
                    $objFile = FilesModel::findOneByUuid($objPicture->uuid);

                    if (null !== $objFile) {
                        if (is_file($this->projectDir.'/'.$objFile->path)) {
                            $oFile = new File($objFile->path);
                            ++$i;

                            while (is_file($oFile->dirname.'/'.$strPrefix.'_'.$i.'.'.strtolower($oFile->extension))) {
                                ++$i;
                            }
                            $oldPath = $oFile->dirname.'/'.$strPrefix.'_'.$i.'.'.strtolower($oFile->extension);
                            $newPath = str_replace($this->projectDir.'/', '', $oldPath);

                            // Rename file
                            if ($oFile->renameTo($newPath)) {
                                $objPicture->path = $oFile->path;
                                $objPicture->save();
                                Message::addInfo(sprintf('Picture with ID %s has been renamed to %s.', $objPicture->id, $newPath));
                            }
                        }
                    }
                }
                // Purge image cache too
                $objAutomator = new Automator();
                $objAutomator->purgeImageCache();
            }
        }

        return '';
    }

    /**
     * Save callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.sortBy.save")
     */
    public function saveCbSortBy(string $varValue, DataContainer $dc): string
    {
        if ('----' === $varValue) {
            return $varValue;
        }

        $objPictures = GalleryCreatorPicturesModel::findByPid($dc->id);

        if (null === $objPictures) {
            return '----';
        }

        $files = [];
        $auxDate = [];

        while ($objPictures->next()) {
            $oFile = FilesModel::findByUuid($objPictures->uuid);
            $objFile = new File($oFile->path, true);
            $files[$oFile->path] = [
                'id' => $objPictures->id,
            ];
            $auxDate[] = $objFile->mtime;
        }

        switch ($varValue) {
            case '----':
                break;

            case 'name_asc':
                uksort($files, 'basename_natcasecmp');
                break;

            case 'name_desc':
                uksort($files, 'basename_natcasercmp');
                break;

            case 'date_asc':
                array_multisort($files, SORT_NUMERIC, $auxDate, SORT_ASC);
                break;

            case 'date_desc':
                array_multisort($files, SORT_NUMERIC, $auxDate, SORT_DESC);
                break;
        }

        $sorting = 0;

        foreach ($files as $arrFile) {
            $sorting += 10;
            $objPicture = GalleryCreatorPicturesModel::findByPk($arrFile['id']);
            $objPicture->sorting = $sorting;
            $objPicture->save();
        }

        // Return default value
        return '----';
    }

    /**
     * Save callback.
     *
     * Generate an unique album alias based on the album name
     * and create a directory with the same name
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.alias.save")
     */
    public function saveCbAlias(string $strAlias, DataContainer $dc): string
    {
        $request = $this->requestStack->getCurrentRequest();

        $blnDoNotCreateDir = false;

        // Get current row
        $objAlbum = GalleryCreatorAlbumsModel::findByPk($dc->id);

        // Save assigned directory if it has been defined.
        if ($this->Input->post('FORM_SUBMIT') && \strlen((string) $this->Input->post('assignedDir'))) {
            $objAlbum->assignedDir = $this->Input->post('assignedDir');
            $objAlbum->save();
            $blnDoNotCreateDir = true;
        }

        $strAlias = StringUtil::standardize($strAlias);

        // If there isn't an existing album alias, generate one based on the album name
        if (!\strlen($strAlias)) {
            $strAlias = standardize($dc->activeRecord->name);
        }

        // Limit alias to 50 characters.
        $strAlias = substr($strAlias, 0, 43);

        // Remove invalid characters.
        $strAlias = preg_replace('/[^a-z0-9\\_\\-]/', '', $strAlias);

        // If alias already exists, append the album id to the alias.
        $albumCount = $this->connection->fetchOne(
            'SELECT COUNT(id) AS albumCount FROM tl_gallery_creator_albums WHERE id != ? AND alias = ?',
            [$dc->activeRecord->id, $strAlias]
        );

        if ($albumCount > 0) {
            $strAlias = 'id-'.$dc->id.'-'.$strAlias;
        }

        // Create default upload folder.
        if (false === $blnDoNotCreateDir) {
            // Create the new folder and register it in tl_files
            $objFolder = new Folder($this->galleryCreatorUploadPath.'/'.$strAlias);
            $objFolder->unprotect();
            $oFolder = Dbafs::addResource($objFolder->path, true);
            $objAlbum->assignedDir = $oFolder->uuid;
            $objAlbum->save();

            // Important
            $request->request->set('assignedDir', StringUtil::binToUuid($objAlbum->assignedDir));
        }

        return $strAlias;
    }

    /**
     * Save callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.imageQuality.save")
     */
    public function saveCbImageQuality(string $value): void
    {
        $this->connection->update('tl_user', ['gcImageQuality' => $value], ['id' => $this->User->id]);
    }

    /**
     * Save callback.
     *
     * @Callback(table="tl_gallery_creator_albums", target="fields.imageResolution.save")
     */
    public function saveCbImageResolution(string $value): void
    {
        $this->connection->update('tl_user', ['gcImageResolution' => $value], ['id' => $this->User->id]);
    }

    /**
     * Checks if the current user has full access or only restricted access to the active album.
     */
    private function checkUserRole($albumId): void
    {
        $objAlbum = GalleryCreatorAlbumsModel::findByPk($albumId);

        if ($this->User->isAdmin || !$this->galleryCreatorBackendWriteProtection) {
            $this->restrictedUser = false;

            return;
        }

        if ($objAlbum->owner !== $this->User->id) {
            $this->restrictedUser = true;

            return;
        }
        // ...so the current user is the album owner
        $this->restrictedUser = false;
    }
}
