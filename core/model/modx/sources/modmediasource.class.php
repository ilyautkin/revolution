<?php
/*
 * This file is part of MODX Revolution.
 *
 * Copyright (c) MODX, LLC. All Rights Reserved.
 *
 * For complete copyright and license information, see the COPYRIGHT and LICENSE
 * files found in the top-level directory of this distribution.
 */

use xPDO\Cache\xPDOCacheManager;
use xPDO\Om\xPDOCriteria;
use xPDO\xPDO;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\Cached\CachedAdapter;
use League\Flysystem\Cached\Storage\Memory as CacheStore;
use League\Flysystem\Cached\Storage\Predis;
use League\Flysystem\Cached\Storage\Memcached;

require_once 'modMediaSourceInterface.php';

/**
 * An abstract base class extend to implement loading your League\Flysystem\AbstractAdapter
 * See: https://flysystem.thephpleague.com/core-concepts/ and https://flysystem.thephpleague.com/creating-an-adapter/
 *
 * @package modx
 * @subpackage sources
 */
abstract class modMediaSource extends modAccessibleSimpleObject implements modMediaSourceInterface
{
    /** @var modX|xPDO $xpdo */
    public $xpdo;
    /** @var modContext $ctx */
    protected $ctx;
    /** @var array $properties */
    protected $properties = [];
    /** @var array $permissions */
    protected $permissions = [];
    /** @var array $errors */
    public $errors = [];

    /** @var bool to enable visibility support */
    protected $visibility_dirs = false;
    protected $visibility_files = false;

    /** @var  FilesystemInterface */
    protected $adapter;

    /** @var  Filesystem */
    protected $filesystem;


    /**
     * Get the default MODX filesystem source
     *
     * @static
     *
     * @param xPDO|modX $xpdo A reference to an xPDO instance
     * @param int $defaultSourceId
     * @param boolean $fallbackToDefault
     *
     * @return modMediaSource|null
     */
    public static function getDefaultSource(xPDO &$xpdo, $defaultSourceId = null, $fallbackToDefault = true)
    {
        if (empty($defaultSourceId)) {
            $defaultSourceId = $xpdo->getOption('default_media_source', null, 1);
        }

        /** @var modMediaSource $defaultSource */
        $defaultSource = $xpdo->getObject('sources.modMediaSource', [
            'id' => $defaultSourceId,
        ]);
        if (empty($defaultSource) && $fallbackToDefault) {
            $c = $xpdo->newQuery('sources.modMediaSource');
            $c->sortby('id', 'ASC');
            $defaultSource = $xpdo->getObject('sources.modMediaSource', $c);
        }

        return $defaultSource;
    }


    /**
     * Get the current working context for the processor
     *
     * @return bool|modContext
     */
    public function getWorkingContext()
    {
        $wctx = isset($this->properties['wctx']) && !empty($this->properties['wctx']) ? $this->properties['wctx'] : '';
        if (!empty($wctx)) {
            $workingContext = $this->xpdo->getContext($wctx);
            if (!$workingContext) {
                return false;
            }
        } else {
            $workingContext =& $this->xpdo->context;
        }
        $this->ctx =& $workingContext;

        return $this->ctx;
    }


    /**
     * Initialize the source
     *
     * @return boolean
     */
    public function initialize()
    {
        $this->setProperties($this->getProperties(true));
        $this->getPermissions();
        $this->xpdo->lexicon->load('file');

        if (!$this->ctx) {
            $this->ctx =& $this->xpdo->context;
        }

        return true;
    }


    /**
     * @return modContext
     */
    public function getContext()
    {
        return $this->ctx;
    }


    /**
     * Setup the request properties for the source, determining any request-specific actions
     *
     * @param array $scriptProperties
     *
     * @return array
     */
    public function setRequestProperties(array $scriptProperties = [])
    {
        if (empty($this->properties)) {
            $this->properties = [];
        }
        $this->properties = array_merge($this->getPropertyList(), $this->properties, $scriptProperties);

        return $this->properties;
    }


    /**
     * Get a list of permissions for browsing and utilizing the source. May be overridden to provide a custom
     * list of permissions.
     *
     * @return array
     */
    public function getPermissions()
    {
        $this->permissions = [
            'directory_chmod' => $this->xpdo->hasPermission('directory_chmod'),
            'directory_create' => $this->xpdo->hasPermission('directory_create'),
            'directory_list' => $this->xpdo->hasPermission('directory_list'),
            'directory_remove' => $this->xpdo->hasPermission('directory_remove'),
            'directory_update' => $this->xpdo->hasPermission('directory_update'),
            'file_list' => $this->xpdo->hasPermission('file_list'),
            'file_remove' => $this->xpdo->hasPermission('file_remove'),
            'file_update' => $this->xpdo->hasPermission('file_update'),
            'file_upload' => $this->xpdo->hasPermission('file_upload'),
            'file_unpack' => $this->xpdo->hasPermission('file_unpack'),
            'file_view' => $this->xpdo->hasPermission('file_view'),
            'file_create' => $this->xpdo->hasPermission('file_create'),
        ];

        return $this->permissions;
    }


    /**
     * See if the source is allowing a certain permission.
     *
     * @param string $key
     *
     * @return bool
     */
    public function hasPermission($key)
    {
        return !empty($this->permissions[$key]);
    }


    /**
     * Add an error for an action occurring in the source
     *
     * @param string $field The field corresponding to the error
     * @param string $message The message to add
     *
     * @return string The added error
     */
    public function addError($field, $message)
    {
        $this->errors[$field] = $message;

        return $message;
    }


    /**
     * Get all errors that have occurred for this source
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }


    /**
     * See if the source has any errors
     *
     * @return bool
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }


    /**
     * @return Filesystem
     */
    public function getFilesystem()
    {
        return $this->filesystem;
    }


    /**
     * @return FilesystemInterface
     */
    public function getAdapter()
    {
        return $this->adapter;
    }


    /**
     * Get base paths/urls and sanitize incoming paths
     *
     * @param string $path A path to the active directory
     *
     * @return array
     */
    public function getBases($path = '')
    {
        $properties = $this->getProperties();
        $bases = [];
        $path = $this->sanitizePath($path);
        $bases['path'] = $properties['basePath']['value'];
        $bases['pathIsRelative'] = false;
        if (!empty($properties['basePathRelative']['value'])) {
            $bases['pathAbsolute'] = realpath("{$this->ctx->getOption('base_path',MODX_BASE_PATH)}{$bases['path']}") . DIRECTORY_SEPARATOR;
            $bases['pathIsRelative'] = true;
        } else {
            $bases['pathAbsolute'] = $bases['path'];
        }

        $bases['pathAbsoluteWithPath'] = $bases['pathAbsolute'] . ltrim($path, DIRECTORY_SEPARATOR);
        if (is_dir($bases['pathAbsoluteWithPath'])) {
            $bases['pathAbsoluteWithPath'] = $this->postfixSlash($bases['pathAbsoluteWithPath']);
        }
        $bases['pathRelative'] = ltrim($path, DIRECTORY_SEPARATOR);

        // get relative url
        $bases['urlIsRelative'] = false;
        $bases['url'] = $properties['baseUrl']['value'];
        if (!empty($properties['baseUrlRelative']['value'])) {
            $bases['urlAbsolute'] = $this->ctx->getOption('base_url', MODX_BASE_URL) . $bases['url'];
            $bases['urlIsRelative'] = true;
        } else {
            $bases['urlAbsolute'] = $bases['url'];
        }

        $bases['urlAbsoluteWithPath'] = $bases['urlAbsolute'] . ltrim($path, DIRECTORY_SEPARATOR);
        $bases['urlRelative'] = ltrim($path, DIRECTORY_SEPARATOR);

        return $bases;
    }


    /**
     * Get the ID of the edit file action
     *
     * @return boolean|int
     */
    protected function getEditActionId()
    {
        return 'system/file/edit';
    }


    /**
     * @return array
     */
    protected function getPropertyListWithDefaults()
    {
        $properties = $this->getPropertyList();

        $properties['use_multibyte'] = $this->getOption('use_multibyte', $properties, false);
        $properties['modx_charset'] = $this->getOption('modx_charset', $properties, 'UTF-8');
        $properties['hideFiles'] = !empty($properties['hideFiles']) && $properties['hideFiles'] != 'false';
        $properties['hideTooltips'] = !empty($properties['hideTooltips']) && $properties['hideTooltips'] != 'false';
        $properties['imageExtensions'] = $this->getOption('imageExtensions', $properties, 'jpg,jpeg,png,gif,svg');

        return $properties;
    }


    /**
     * Return an array of files and folders at this current level in the directory structure
     *
     * @param string $path
     *
     * @return array
     */
    public function getContainerList($path)
    {
        $properties = $this->getPropertyListWithDefaults();
        $path = $this->postfixSlash($path);
        if ($path == DIRECTORY_SEPARATOR || $path == '\\') {
            $path = '';
        }

        $bases = $this->getBases($path);
        $imageExtensions = explode(',', $properties['imageExtensions']);
        $skipFiles = $this->getSkipFilesArray($properties);
        $allowedExtensions = $this->getAllowedExtensionsArray($properties);

        $directories = $dirNames = $files = $fileNames = [];
        if (!empty($path)) {
            try {
                $meta = $this->filesystem->getMetadata($path);
            } catch (Exception $e) {
                $this->addError('path', $e->getMessage());

                return [];
            }
            if (isset($meta['type']) && $meta['type'] != 'dir') {
                $this->addError('path', $this->xpdo->lexicon('file_folder_err_invalid'));

                return [];
            }
        }

        try {
            $re = '#^(.*?/|)(' . implode('|', array_map('preg_quote', $skipFiles)) . ')/?$#';
            /** @var array $contents */
            $contents = $this->filesystem->listContents($path);
            foreach ($contents as $object) {
                if (preg_match($re, $object['path'])) {
                    continue;
                }
                $file_name = array_pop(explode(DIRECTORY_SEPARATOR, $object['path']));

                if ($object['type'] == 'dir' && $this->hasPermission('directory_list')) {
                    $cls = $this->getExtJSDirClasses();
                    $dirNames[] = strtoupper($file_name);
                    $visibility = $this->visibility_dirs ? $this->getVisibility($object['path']) : false;
                    $directories[$file_name] = [
                        'id' => rawurlencode(rtrim($object['path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR),
                        'sid' => $this->get('id'),
                        'text' => $file_name,
                        'cls' => implode(' ', $cls),
                        'iconCls' => 'icon ' . ($visibility == \League\Flysystem\AdapterInterface::VISIBILITY_PRIVATE
                                ? 'icon-eye-slash' : 'icon-folder'),
                        'type' => 'dir',
                        'leaf' => false,
                        'path' => $object['path'],
                        'pathRelative' => $object['path'],
                        'menu' => [],
                    ];
                    if ($this->visibility_dirs && $visibility) {
                        $directories[$file_name]['visibility'] = $visibility;
                    }
                    $directories[$file_name]['menu'] = [
                        'items' => $this->getListDirContextMenu(),
                    ];

                } elseif ($object['type'] == 'file' && !$properties['hideFiles'] && $this->hasPermission('file_list')) {
                    // @TODO review/refactor extension and mime_type would be better for filesystems that
                    // may not always have an extension on it. For example would be S3 and you have an HTML file
                    // but the name is just myPage - $this->filesystem->getMimetype($object['path']);
                    $ext = array_pop(explode('.', $object['path']));
                    $ext = $properties['use_multibyte']
                        ? mb_strtolower($ext, $properties['modx_charset'])
                        : strtolower($ext);
                    if (!empty($allowedExtensions) && !in_array($ext, $allowedExtensions)) {
                        continue;
                    }
                    $fileNames[] = strtoupper($file_name);
                    $files[$file_name] = $this->buildFileList($object['path'], $ext, $imageExtensions, $bases, $properties);
                }
            }

            $ls = [];
            // now sort files/directories
            array_multisort($dirNames, SORT_ASC, SORT_STRING, $directories);
            foreach ($directories as $dir) {
                $ls[] = $dir;
            }

            array_multisort($fileNames, SORT_ASC, SORT_STRING, $files);
            foreach ($files as $file) {
                $ls[] = $file;
            }

            return $ls;
        } catch (Exception $e) {
            $this->addError('path', $e->getMessage());

            return [];
        }
    }


    /**
     * Get a list of files in a specific directory.
     *
     * @param string $path
     *
     * @return array
     */
    public function getObjectsInContainer($path)
    {
        $properties = $this->getPropertyListWithDefaults();
        $path = $this->postfixSlash($path);
        $bases = $this->getBases($path);

        $fullPath = $path;
        if (!empty($bases['pathAbsolute'])) {
            $fullPath = $bases['pathAbsolute'] . ltrim($path, DIRECTORY_SEPARATOR);
        }

        $imageExtensions = explode(',', $properties['imageExtensions']);
        $skipFiles = $this->getSkipFilesArray($properties);

        $allowedExtensions = $this->getAllowedExtensionsArray($properties);

        $files = $fileNames = [];

        if (!empty($path) && $path != DIRECTORY_SEPARATOR) {
            try {
                $meta = $this->filesystem->getMetadata($path);
            } catch (Exception $e) {
                $this->addError('path', $e->getMessage());

                return [];
            }
            if (isset($meta['type']) && $meta['type'] != 'dir') {
                $this->addError('path', $this->xpdo->lexicon('file_folder_err_invalid'));

                return [];
            }
        }

        /** @var array $contents */
        $contents = $this->filesystem->listContents($path);
        foreach ($contents as $object) {
            if (in_array($object['path'], $skipFiles) || in_array(trim($object['path'], DIRECTORY_SEPARATOR), $skipFiles) || (in_array($fullPath . $object['path'], $skipFiles))) {
                continue;
            }
            if ($object['type'] == 'dir' && !$this->hasPermission('directory_list')) {
                continue;
            } elseif ($object['type'] == 'file' && !$properties['hideFiles'] && $this->hasPermission('file_list')) {
                // @TODO review/refactor ext and mime_type would be better for filesystems that may not always have an extension on it
                // example would be S3 and you have an HTML file but the name is just myPage
                //$this->filesystem->getMimetype($object['path']);
                $ext = array_pop(explode('.', $object['path']));
                $ext = $properties['use_multibyte']
                    ? mb_strtolower($ext, $properties['modx_charset'])
                    : strtolower($ext);
                if (!empty($allowedExtensions) && !in_array($ext, $allowedExtensions)) {
                    continue;
                }
                $fileNames[] = strtoupper($object['path']);

                $files[$object['path']] = $this->buildFileBrowserViewList($object['path'], $ext, $imageExtensions, $bases, $properties);
            }
        }

        $ls = [];
        // now sort files/directories
        array_multisort($fileNames, SORT_ASC, SORT_STRING, $files);
        foreach ($files as $file) {
            $ls[] = $file;
        }

        return $ls;
    }


    /**
     * @param $path
     *
     * @return array|bool
     */
    public function getMetaData($path)
    {
        try {
            $data = $this->filesystem->getMetadata($path);
        } catch (Exception $e) {
            $data = false;
        }

        return $data;
    }


    /**
     * Get the contents of a specified file
     *
     * @param string $path
     *
     * @return array
     */
    public function getObjectContents($path)
    {
        try {
            /** @var League\Flysystem\File $file */
            $file = $this->filesystem->get($path);
        } catch (\League\Flysystem\FileNotFoundException $e) {
            $this->addError('path', $this->xpdo->lexicon('file_err_nf'));

            return [];
        } catch (Exception $e) {
            $this->addError('path', $e->getMessage());

            return [];
        }

        if (!$file->isFile()) {
            $this->addError('file', $this->xpdo->lexicon('file_err_nf'));

            return [];
        }

        $properties = $this->getPropertyList();
        $imageExtensions = array_map('trim', explode(',', $this->getOption('imageExtensions', $properties, 'jpg,jpeg,png,gif,svg')));
        $fa = [
            'name' => rtrim($path, DIRECTORY_SEPARATOR),
            'basename' => array_pop(explode(DIRECTORY_SEPARATOR, $file->getPath())),
            'path' => $file->getPath(),
            'size' => $file->getSize(),
            'last_accessed' => $file->getTimestamp(),
            'last_modified' => $file->getTimestamp(),
            'content' => $file->read(),
            'mime' => $file->getMimetype(),
            'image' => $this->isFileImage($path, $imageExtensions),
        ];
        $visibility = $this->visibility_files ? $this->getVisibility($path) : false;
        if ($visibility) {
            $fa['visibility'] = $visibility;
        }

        return $fa;
    }


    /**
     * Create a filesystem folder
     *
     * @param string $name
     * @param string $parentContainer
     *
     * @return boolean
     */
    public function createContainer($name, $parentContainer)
    {
        if ($parentContainer == DIRECTORY_SEPARATOR) {
            $parentContainer = '';
        }
        $path = $this->sanitizePath($parentContainer . DIRECTORY_SEPARATOR . ltrim($name, DIRECTORY_SEPARATOR));

        try {
            if ($this->filesystem->has($path)) {
                $this->addError('name', $this->xpdo->lexicon('file_folder_err_ae'));

                return false;
            }
            $config = [];
            if ($this->visibility_dirs) {
                /** @var array $properties */
                $properties = $this->getPropertyList();
                $config['visibility'] = $this->xpdo->getOption('visibility', $properties, League\Flysystem\Adapter\AbstractAdapter::VISIBILITY_PUBLIC);
            }
            if (!$this->filesystem->createDir($path, $config)) {
                $this->addError('name', $this->xpdo->lexicon('file_folder_err_create'));

                return false;
            }

        } catch (Exception $e) {
            $this->addError('name', $e->getMessage());

            return false;
        }

        $this->xpdo->invokeEvent('OnFileManagerDirCreate', [
            'directory' => $path,
            'source' => &$this,
        ]);
        $this->xpdo->logManagerAction('directory_create', '', "{$this->get('name')}: $path");

        return true;
    }


    /**
     * Create a file
     *
     * @param string $path
     * @param string $name
     * @param string $content
     *
     * @return boolean|string
     */
    public function createObject($path, $name, $content)
    {
        if ($path == DIRECTORY_SEPARATOR) {
            $path = '';
        }
        $path = !empty($path)
            ? $this->sanitizePath($path . DIRECTORY_SEPARATOR . ltrim($name, DIRECTORY_SEPARATOR))
            : $name;

        if (!$this->checkFileType($path)) {
            return false;
        }

        try {
            if ($this->filesystem->has($path)) {
                $this->addError('name', sprintf($this->xpdo->lexicon('file_err_ae'), $name));

                return false;
            }

            $config = [];
            if ($this->visibility_files) {
                $properties = $this->getPropertyList();
                $config['visibility'] = $this->xpdo->getOption('visibility', $properties, League\Flysystem\Adapter\AbstractAdapter::VISIBILITY_PUBLIC);
            }
            if (!$this->filesystem->put($path, $content, $config)) {
                $this->addError('name', $this->xpdo->lexicon('file_err_create'));

                return false;
            }
        } catch (Exception $e) {
            $this->addError('name', $e->getMessage());

            return false;
        }

        $this->xpdo->invokeEvent('OnFileManagerFileCreate', [
            'path' => $path,
            'source' => &$this,
        ]);
        $this->xpdo->logManagerAction('file_create', '', "{$this->get('name')}: $path");

        return rawurlencode($this->getBases()['pathAbsolute'] . $path);
    }


    /**
     * Move a file or folder to a specific location
     *
     * @param string $from The location to move from
     * @param string $to The location to move to
     * @param string $point @TODO what is this for?
     * @param int $to_source
     *
     * @return boolean
     */
    public function moveObject($from, $to, $point = 'append', $to_source = 0)
    {
        $path = $this->postfixSlash($from);
        $to = $this->postfixSlash($to);
        $newPath = rtrim($to, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . array_pop(explode(DIRECTORY_SEPARATOR, rtrim($from, DIRECTORY_SEPARATOR)));

        try {
            /** @var League\Flysystem\Directory|League\Flysystem\File $originalObject */
            $originalObject = $this->filesystem->get($path);
            if ($originalObject->isDir()) {
                $newPath = $this->postfixSlash($newPath);
            }
        } catch (\League\Flysystem\FileNotFoundException $e) {
            $this->addError('path', $this->xpdo->lexicon('file_err_nf'));

            return false;
        } catch (Exception $e) {
            $this->addError('path', $e->getMessage());

            return false;
        }

        if ($to_source) {
            if ($originalObject->isDir()) {
                $this->addError('source', $this->xpdo->lexicon('no_move_folder'));

                return false;
            }
            $this->xpdo->loadClass('sources.modMediaSource');
            /** @var modMediaSource $toSource */
            $toSource = modMediaSource::getDefaultSource($this->xpdo, $to_source);
            if (!$toSource->getWorkingContext()) {
                $this->addError('source', $this->xpdo->lexicon('permission_denied'));

                return false;
            }

            if (!$toSource->initialize() || !$toSource->checkPolicy('save')) {
                $this->addError('source', $this->xpdo->lexicon('permission_denied'));

                return false;
            }

            /** @var League\Flysystem\Filesystem $destination */
            try {
                $destination = $toSource->getFilesystem();
                /** @var League\Flysystem\MountManager $mountManager */
                $mountManager = new League\Flysystem\MountManager([
                    'org' => $this->filesystem,
                    'destination' => $destination,
                ]);
                $mountManager->move(
                    'org://' . ltrim($path, DIRECTORY_SEPARATOR),
                    'destination://' . ltrim($newPath, DIRECTORY_SEPARATOR)
                );
            } catch (Exception $e) {
                $this->addError('source', $e->getMessage());

                return false;
            }
        } else {
            $toSource = false;
            try {
                $this->filesystem->rename($path, $newPath);
            } catch (\League\Flysystem\FileNotFoundException $e) {
                $this->addError('from', $this->xpdo->lexicon('file_err_rename'));

                return false;
            } catch (Exception $e) {
                $this->addError('from', $e->getMessage());

                return false;
            }
        }

        $this->xpdo->invokeEvent('OnFileManagerMoveObject', [
            'from' => $path,
            'to' => $newPath,
            'source' => $this,
            'toSource' => $toSource,
        ]);

        return true;
    }


    /**
     * Remove a folder at the specified location
     *
     * @param string $path ~ pre 3.0 $path was full absolute path, all paths need to be relative
     *
     * @return boolean
     */
    public function removeContainer($path)
    {
        $path = $this->postfixSlash($path);

        /** @var League\Flysystem\Directory $dirObject */
        try {
            $dirObject = $this->filesystem->get($path);
        } catch (\League\Flysystem\FileNotFoundException $e) {
            $this->addError('path', $this->xpdo->lexicon('file_folder_err_invalid'));

            return false;
        } catch (Exception $e) {
            $this->addError('path', $e->getMessage());

            return false;
        }

        if (!$dirObject->isDir()) {
            $this->addError('path', $this->xpdo->lexicon('file_folder_err_invalid'));

            return false;
        }

        try {
            if (!$this->filesystem->deleteDir($path)) {
                $this->addError('path', $this->xpdo->lexicon('file_folder_err_remove'));

                return false;
            }
        } catch (Exception $e) {
            $this->addError('path', $e->getMessage());

            return false;
        }

        $this->xpdo->invokeEvent('OnFileManagerDirRemove', [
            'directory' => $path,
            'source' => &$this,
        ]);
        $this->xpdo->logManagerAction('directory_remove', '', "{$this->get('name')}: $path");

        return true;
    }


    /**
     * Remove a file
     *
     * @param string $path
     *
     * @return boolean
     */
    public function removeObject($path)
    {
        if (!$this->filesystem->has($path)) {
            $this->addError('file', $this->xpdo->lexicon('file_err_nf') . ': ' . $path);

            return false;
        }

        try {
            if (!$this->filesystem->delete($path)) {
                $this->addError('file', $this->xpdo->lexicon('file_err_remove'));

                return false;
            }
        } catch (Exception $e) {
            $this->addError('file', $e->getMessage());

            return false;
        }

        $this->xpdo->invokeEvent('OnFileManagerFileRemove', [
            'directory' => $path,
            'source' => &$this,
        ]);
        $this->xpdo->logManagerAction('file_remove', '', "{$this->get('name')}: $path");

        return true;
    }


    /**
     * @param string $oldPath
     * @param string $newName
     *
     * @return bool
     */
    public function renameContainer($oldPath, $newName)
    {
        $oldPath = trim($oldPath, DIRECTORY_SEPARATOR);
        if (strpos($oldPath, DIRECTORY_SEPARATOR)) {
            $path = explode(DIRECTORY_SEPARATOR, $oldPath);
            array_pop($path);
            $newPath = implode(DIRECTORY_SEPARATOR, $path) . DIRECTORY_SEPARATOR . $newName;
        } else {
            $newPath = $newName;
        }
        $oldPath = $this->sanitizePath($oldPath) . DIRECTORY_SEPARATOR;
        $newPath = $this->sanitizePath($newPath) . DIRECTORY_SEPARATOR;

        if (!$this->filesystem->has($oldPath)) {
            $this->addError('name', $this->xpdo->lexicon('file_folder_err_invalid'));

            return false;
        } elseif ($this->filesystem->has($newPath)) {
            $this->addError('name', $this->xpdo->lexicon('file_folder_err_ae'));

            return false;
        }

        try {
            if (!$this->filesystem->rename($oldPath, $newPath)) {
                $this->addError('name', $this->xpdo->lexicon('file_folder_err_rename'));

                return false;
            }
        } catch (Exception $e) {
            $this->addError('name', $e->getMessage());

            return false;
        }

        $this->xpdo->invokeEvent('OnFileManagerDirRename', [
            'directory' => $newPath,
            'source' => &$this,
        ]);
        $this->xpdo->logManagerAction('directory_rename', '', "{$this->get('name')}: $oldPath -> $newPath");

        return true;
    }


    /**
     * @param string $oldPath
     * @param string $newName
     *
     * @return bool
     */
    public function renameObject($oldPath, $newName)
    {
        $oldPath = trim($oldPath, DIRECTORY_SEPARATOR);
        if (strpos($oldPath, DIRECTORY_SEPARATOR)) {
            $path = explode(DIRECTORY_SEPARATOR, $oldPath);
            array_pop($path);
            $newPath = implode(DIRECTORY_SEPARATOR, $path) . DIRECTORY_SEPARATOR . $newName;
        } else {
            $newPath = $newName;
        }
        $oldPath = $this->sanitizePath($oldPath);
        $newPath = $this->sanitizePath($newPath);

        if (!$this->filesystem->has($oldPath)) {
            $this->addError('name', $this->xpdo->lexicon('file_err_invalid'));

            return false;
        } elseif ($this->filesystem->has($newPath)) {
            $this->addError('name', sprintf($this->xpdo->lexicon('file_err_ae'), $newName));

            return false;
        } elseif (!$this->checkFileType($newName)) {
            return false;
        }

        try {
            if (!$this->filesystem->rename($oldPath, $newPath)) {
                $this->addError('name', $this->xpdo->lexicon('file_folder_err_rename'));

                return false;
            }
        } catch (Exception $e) {
            $this->addError('name', $e->getMessage());

            return false;
        }

        $this->xpdo->invokeEvent('OnFileManagerFileRename', [
            'path' => $newPath,
            'source' => &$this,
        ]);
        $this->xpdo->logManagerAction('file_rename', '', "{$this->get('name')}: $oldPath -> $newPath");

        return true;
    }


    /**
     * Update the contents of a file
     *
     * @param string $path
     * @param string $content
     *
     * @return boolean|string
     */
    public function updateObject($path, $content)
    {
        $path = $this->sanitizePath($path);

        if (!$this->checkFileType($path)) {
            return false;
        } elseif (!$this->filesystem->has($path)) {
            $this->addError('file', $this->xpdo->lexicon('file_err_nf') . ': ' . $path);

            return false;
        }
        $config = [];
        if ($this->visibility_files) {
            $config['visibility'] = $this->getVisibility($path);
        }

        try {
            if (!$this->filesystem->update($path, $content, $config)) {
                $this->addError('name', $this->xpdo->lexicon('file_folder_err_update'));

                return false;
            }
        } catch (Exception $e) {
            $this->addError('name', $e->getMessage());

            return false;
        }

        $this->xpdo->invokeEvent('OnFileManagerFileUpdate', [
            'path' => $path,
            'source' => &$this,
        ]);
        $this->xpdo->logManagerAction('file_update', '', "{$this->get('name')}: $path");

        return rawurlencode($path);
    }


    /**
     * Upload files to a specific folder on the file system
     *
     * @param string $container
     * @param array $objects
     *
     * @return boolean
     */
    public function uploadObjectsToContainer($container, array $objects = [])
    {
        $container = $this->postfixSlash($container);
        /** @var array $properties */
        $properties = $this->getPropertyList();
        $visibility = $this->xpdo->getOption('visibility', $properties, League\Flysystem\Adapter\AbstractAdapter::VISIBILITY_PUBLIC);

        if ($container != DIRECTORY_SEPARATOR) {
            try {
                $this->filesystem->has($container);
            } catch (\League\Flysystem\FileNotFoundException $e) {
                $this->addError('path', $this->xpdo->lexicon('file_folder_err_invalid') . ': ' . $container);

                return false;
            } catch (Exception $e) {
                $this->addError('path', $e->getMessage());

                return false;
            }
        }

        $this->xpdo->context->prepare();
        $maxFileSize = $this->xpdo->getOption('upload_maxsize', null, 1048576);

        foreach ($objects as $file) {
            $this->xpdo->invokeEvent('OnFileManagerBeforeUpload', [
                'files' => &$objects,
                'file' => &$file,
                'directory' => $container,
                'source' => &$this,
            ]);

            if ($file['error'] != 0 || empty($file['name']) || !$this->checkFileType($file['name'])) {
                continue;
            }

            $size = filesize($file['tmp_name']);
            if ($size > $maxFileSize) {
                $this->addError('path', $this->xpdo->lexicon('file_err_too_large', [
                    'size' => $size,
                    'allowed' => $maxFileSize,
                ]));
                continue;
            }

            if ((boolean)$this->xpdo->getOption('upload_translit')) {
                $file['name'] = $this->xpdo->filterPathSegment($file['name']);
            }

            $newPath = $container . $this->sanitizePath($file['name']);

            /* Check if file already exists. */
            if ($this->filesystem->has($newPath)) {
                $newPath = $this->generateUniqueFilename($newPath);
            }

            try {
                if (!$this->filesystem->put($newPath, file_get_contents($file['tmp_name']))) {
                    $this->addError('path', $this->xpdo->lexicon('file_err_upload'));
                    continue;
                }
                if ($this->visibility_files) {
                    $this->filesystem->setVisibility($newPath, $visibility);
                }
            } catch (Exception $e) {
                $this->addError('path', $e->getMessage());
                continue;
            }
        }

        $this->xpdo->invokeEvent('OnFileManagerUpload', [
            'files' => &$objects,
            'directory' => $container,
            'source' => &$this,
        ]);
        $this->xpdo->logManagerAction('file_upload', '', "{$this->get('name')}: $container");

        return !$this->hasErrors();
    }

    /**
     * This will return a unique filename based on the current file path.
     *
     * @param $path
     * @return string|null
     */
    public function generateUniqueFilename($path) {
        $pathInfo = pathinfo($path);
        $result   = null;
        $counter  = 1;

        while (!$result) {
            $newFilename  = sprintf($pathInfo['filename'] . '-%s' . '.%s', $counter, $pathInfo['extension']);
            $newPathArray = explode('/', $path);

            /* Remove filename from array. */
            array_pop($newPathArray);

            /* Implode file path and add the new filename. */
            $newPath = implode('/', $newPathArray) . '/' . $newFilename;
            if (!$this->filesystem->has($newPath)) {
                $result = $newPath;
            }

            $counter++;
        }

        return $result;
    }

    /**
     * @param string $path ~ relative path of file/directory
     *
     * @return bool
     */
    public function getVisibility($path)
    {
        $path = $this->sanitizePath($path);
        try {
            $data = $this->filesystem->getMetadata($path);
            if (($data['type'] == 'dir' && $this->visibility_dirs) || ($data['type'] == 'file' && $this->visibility_files)) {
                return $this->filesystem->getVisibility($path);
            }
        } catch (League\Flysystem\FileNotFoundException $e) {
            $this->addError('path', $this->xpdo->lexicon('file_err_nf'));
        } catch (Exception $e) {
            $this->addError('path', $e->getMessage());
        }

        return false;
    }


    /**
     * @param string $path ~ relative path of file/directory
     * @param string $visibility ~ public or private
     *
     * @return bool
     */
    public function setVisibility($path, $visibility)
    {
        $path = $this->sanitizePath($path);
        try {
            $data = $this->filesystem->getMetadata($path);
            if (($data['type'] == 'dir' && $this->visibility_dirs) || ($data['type'] == 'file' && $this->visibility_files)) {
                return $this->filesystem->setVisibility($path, $visibility);
            }
        } catch (League\Flysystem\FileNotFoundException $e) {
            $this->addError('path', $this->xpdo->lexicon('file_err_nf'));
        } catch (Exception $e) {
            $this->addError('path', $e->getMessage());
        }

        return false;
    }


    /**
     * @param string $object
     *
     * @return string
     */
    public function getBasePath($object = '')
    {
        return '';
    }


    /**
     * @return array
     */
    public function getDefaultProperties()
    {
        return [];
    }


    /**
     * Get the openTo directory for this source, used with TV input types
     *
     * @param string $value
     * @param array $parameters
     *
     * @return string
     */
    public function getOpenTo($value,array $parameters = array()) {
        $dirname = dirname($value);
        return $dirname == '.' ? '' : $dirname . '/';
    }


    /**
     * Get the name of this source type
     *
     * @return string
     */
    public function getTypeName()
    {
        $this->xpdo->lexicon->load('source');

        return $this->xpdo->lexicon('source_type.file');
    }


    /**
     * Get the description of this source type
     *
     * @return string
     */
    public function getTypeDescription()
    {
        $this->xpdo->lexicon->load('source');

        return $this->xpdo->lexicon('source_type.file_desc');
    }


    /**
     * Get the base URL for this source.
     *
     * @param string $object
     *
     * @return mixed
     */
    public function getBaseUrl($object = '')
    {
        $properties = $this->getPropertyList();

        return $properties['baseUrl'];
    }


    /**
     * Get the absolute URL for a specified object.
     *
     * @param string $object
     *
     * @return bool|string
     */
    public function getObjectUrl($object = '')
    {
        $properties = $this->getPropertyList();

        return !empty($properties['baseUrl'])
            ? rtrim($properties['baseUrl'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $object
            : false;
    }


    /**
     * Get the properties on this source
     *
     * @param boolean $parsed
     *
     * @return array
     */
    public function getProperties($parsed = false)
    {
        $properties = $this->get('properties');
        $defaultProperties = $this->getDefaultProperties();
        if (!empty($properties) && is_array($properties)) {
            foreach ($properties as &$property) {
                $property['overridden'] = 0;
                if (array_key_exists($property['name'], $defaultProperties)) {
                    if ($defaultProperties[$property['name']]['value'] != $property['value']) {
                        $property['overridden'] = 1;
                    }
                } else {
                    $property['overridden'] = 2;
                }
            }
            $properties = array_merge($defaultProperties, $properties);
        } else {
            $properties = $defaultProperties;
        }
        /** @var array $results Allow manipulation of media source properties via event */
        $results = $this->xpdo->invokeEvent('OnMediaSourceGetProperties', [
            'properties' => is_string($properties) ? json_decode($properties, true) : $properties,
        ]);
        if (!empty($results)) {
            foreach ($results as $result) {
                $result = is_array($result) ? $result : json_decode($result, true);
                if (!is_array($result)) {
                    $result = [];
                }
                $properties = array_merge($properties, $result);
            }
        }
        if ($parsed) {
            $properties = $this->parseProperties($properties);
        }

        return $this->prepareProperties($properties);
    }


    /**
     * Get all properties in key => value format
     *
     * @return array
     */
    public function getPropertyList()
    {
        $properties = $this->getProperties(true);
        $list = [];
        foreach ($properties as $property) {
            $value = $property['value'];
            if (empty($value) && !empty($this->properties[$property['name']])) {
                $value = $this->properties[$property['name']];
            }
            if (!empty($property['xtype']) && $property['xtype'] == 'combo-boolean') {
                $value = empty($property['value']) && $property['value'] != 'true' ? false : true;
            }
            $list[$property['name']] = $value;
        }
        $list = array_merge($this->properties, $list);

        return $list;
    }


    /**
     * Parse any tags in the properties
     *
     * @param array $properties
     *
     * @return array
     */
    public function parseProperties(array $properties)
    {
        if (empty($properties)) {
            $properties = $this->getProperties();
        }
        $this->xpdo->getParser();
        if ($this->xpdo->parser) {
            foreach ($properties as &$property) {
                $this->xpdo->parser->processElementTags('', $property['value'], true, true);
            }
        }

        return $properties;
    }


    /**
     * Translate any needed properties
     *
     * @param array $properties
     *
     * @return array
     */
    public function prepareProperties(array $properties = [])
    {
        foreach ($properties as &$property) {
            if (!empty($property['lexicon'])) {
                $this->xpdo->lexicon->load($property['lexicon']);
            }
            if (!empty($property['name'])) {
                $property['name_trans'] = $this->xpdo->lexicon($property['name']);
            }
            if (!empty($property['desc'])) {
                $property['desc_trans'] = $this->xpdo->lexicon($property['desc']);
            }
            if (!empty($property['options'])) {
                foreach ($property['options'] as &$option) {
                    if (empty($option['text']) && !empty($option['name'])) {
                        $option['text'] = $option['name'];
                        unset($option['name']);
                    }
                    if (empty($option['value']) && !empty($option[0])) {
                        $option['value'] = $option[0];
                        unset($option[0]);
                    }
                    $option['name'] = $this->xpdo->lexicon($option['text']);
                }
            }
        }

        return $properties;
    }


    /**
     * Set the properties for this Source
     *
     * @param array $properties
     * @param boolean $merge
     *
     * @return bool
     */
    public function setProperties($properties, $merge = false)
    {
        $default = $this->getDefaultProperties();

        foreach ($properties as $k => $prop) {
            if (is_array($prop) && array_key_exists($prop['name'], $default)) {
                if ($prop['value'] == $default[$prop['name']]['value']) {
                    unset($properties[$k]);
                }
            } elseif (is_scalar($prop)) {
                // properties is k=>v pair
                if ($prop == $default[$k]['value']) {
                    unset($properties[$k]);
                }
            }
        }

        $set = false;
        $propertiesArray = [];
        if (is_string($properties)) {
            $properties = $this->xpdo->parser->parsePropertyString($properties);
        }
        if (is_array($properties)) {
            foreach ($properties as $propKey => $property) {
                if (is_array($property) && isset($property[5])) {
                    $key = $property[0];
                    $propertyArray = [
                        'name' => $property[0],
                        'desc' => $property[1],
                        'type' => $property[2],
                        'options' => $property[3],
                        'value' => $property[4],
                        'lexicon' => !empty($property[5]) ? $property[5] : null,
                    ];
                } elseif (is_array($property) && isset($property['value'])) {
                    $key = $property['name'];
                    $propertyArray = [
                        'name' => $property['name'],
                        'desc' => isset($property['description']) ? $property['description']
                            : (isset($property['desc']) ? $property['desc'] : ''),
                        'type' => isset($property['xtype']) ? $property['xtype']
                            : (isset($property['type']) ? $property['type'] : 'textfield'),
                        'options' => isset($property['options']) ? $property['options'] : [],
                        'value' => $property['value'],
                        'lexicon' => !empty($property['lexicon']) ? $property['lexicon'] : null,
                    ];
                } else {
                    $key = $propKey;
                    $propertyArray = [
                        'name' => $propKey,
                        'desc' => '',
                        'type' => 'textfield',
                        'options' => [],
                        'value' => $property,
                        'lexicon' => null,
                    ];
                }

                if (!empty($propertyArray['options'])) {
                    foreach ($propertyArray['options'] as $optionKey => &$option) {
                        if (empty($option['text']) && !empty($option['name'])) $option['text'] = $option['name'];
                        unset($option['menu'], $option['name']);
                    }
                }

                if ($propertyArray['type'] == 'combo-boolean' && is_numeric($propertyArray['value'])) {
                    $propertyArray['value'] = (boolean)$propertyArray['value'];
                }

                $propertiesArray[$key] = $propertyArray;
            }

            if ($merge && !empty($propertiesArray)) {
                $existing = $this->get('properties');
                if (is_array($existing) && !empty($existing)) {
                    $propertiesArray = array_merge($existing, $propertiesArray);
                }
            }
            $set = $this->set('properties', $propertiesArray);
        }

        return $set;
    }


    /**
     * Prepare a src parameter to be rendered with phpThumb
     *
     * @param string $src
     *
     * @return string
     */
    public function prepareSrcForThumb($src)
    {
        try {
            if (!$this->filesystem->has($src)) {
                return '';
            }
        } catch (Exception $e) {
            return '';
        }

        $properties = $this->getPropertyList();
        if ($properties['url']) {
            $src = $properties['url'] . DIRECTORY_SEPARATOR . ltrim($src, DIRECTORY_SEPARATOR);
        }

        // don't strip stuff for absolute URLs
        if (substr($src, 0, 4) != 'http') {
            if (strpos($src, DIRECTORY_SEPARATOR) !== 0) {
                $src = !empty($properties['basePath']) ? $properties['basePath'] . $src : $src;
                if (!empty($properties['basePathRelative'])) {
                    $src = $this->ctx->getOption('base_path', null, MODX_BASE_PATH) . $src;
                }
            }
            // strip out double slashes
            $src = str_replace(['///', '//'], DIRECTORY_SEPARATOR, $src);

            // check for file existence if local url
            if (strpos($src, DIRECTORY_SEPARATOR) !== 0 && empty($src)) {
                if (file_exists(DIRECTORY_SEPARATOR . $src)) {
                    $src = DIRECTORY_SEPARATOR . $src;
                } else {
                    return '';
                }
            }
        }

        return $src;
    }


    /**
     * Prepares the output URL when the Source is being used in an Element. Can be overridden to provide prefixing/post-
     * fixing functionality.
     *
     * @param string $value
     *
     * @return string
     */
    public function prepareOutputUrl($value)
    {
        $url = $this->getObjectUrl($value);

        return $url ?: $value;
    }


    /**
     * Find all policies for this object
     *
     * @param string $context
     *
     * @return array
     */
    public function findPolicy($context = '')
    {
        $policy = [];
        $enabled = true;
        $context = 'mgr';
        if ($context === $this->xpdo->context->get('key')) {
            $enabled = (boolean)$this->xpdo->getOption('access_media_source_enabled', null, true);
        } elseif ($obj = $this->xpdo->getContext($context)) {
            $enabled = (boolean)$obj->getOption('access_media_source_enabled', true);
        }
        if ($enabled) {
            if (empty($this->_policies) || !isset($this->_policies[$context])) {
                $accessTable = $this->xpdo->getTableName('sources.modAccessMediaSource');
                $sourceTable = $this->xpdo->getTableName('sources.modMediaSource');
                $policyTable = $this->xpdo->getTableName('modAccessPolicy');
                $sql = "SELECT Acl.target, Acl.principal, Acl.authority, Acl.policy, Policy.data FROM {$accessTable} Acl " .
                    "LEFT JOIN {$policyTable} Policy ON Policy.id = Acl.policy " .
                    "JOIN {$sourceTable} Source ON Acl.principal_class = 'modUserGroup' " .
                    "AND (Acl.context_key = :context OR Acl.context_key IS NULL OR Acl.context_key = '') " .
                    "AND Source.id = Acl.target " .
                    "WHERE Acl.target = :source " .
                    "GROUP BY Acl.target, Acl.principal, Acl.authority, Acl.policy";
                $bindings = [
                    ':source' => $this->get('id'),
                    ':context' => $context,
                ];
                $query = new xPDOCriteria($this->xpdo, $sql, $bindings);
                if ($query->stmt && $query->stmt->execute()) {
                    while ($row = $query->stmt->fetch(PDO::FETCH_ASSOC)) {
                        $policy['sources.modAccessMediaSource'][$row['target']][] = [
                            'principal' => $row['principal'],
                            'authority' => $row['authority'],
                            'policy' => $row['data'] ? json_decode($row['data'], true) : [],
                        ];
                    }
                }
                $this->_policies[$context] = $policy;
            } else {
                $policy = $this->_policies[$context];
            }
        }

        return $policy;
    }


    /**
     * Allow overriding of checkPolicy to always allow media sources to be loaded
     *
     * @param string|array $criteria
     * @param array $targets
     * @param modUser $user
     *
     * @return bool
     */
    public function checkPolicy($criteria, $targets = null, modUser $user = null)
    {
        if ($criteria == 'load') {
            $success = true;
        } else {
            $success = parent::checkPolicy($criteria, $targets, $user);
        }

        return $success;
    }


    /**
     * Override xPDOObject::save to clear the sources cache on save
     *
     * @param boolean $cacheFlag
     *
     * @return boolean
     */
    public function save($cacheFlag = null)
    {
        $saved = parent::save($cacheFlag);
        if ($saved) {
            $this->clearCache();
        }

        return $saved;
    }


    /**
     * Clear the caches of all sources
     *
     * @param array $options
     *
     * @return void
     */
    public function clearCache(array $options = [])
    {
        /** @var modCacheManager $cacheManager */
        $cacheManager = $this->xpdo->getCacheManager();
        if (empty($cacheManager)) {
            return;
        }

        $c = $this->xpdo->newQuery('modContext');
        $c->select($this->xpdo->escape('key'));

        $options[xPDO::OPT_CACHE_KEY] = $this->getOption('cache_media_sources_key', $options, 'media_sources');
        $options[xPDO::OPT_CACHE_HANDLER] = $this->getOption('cache_media_sources_handler', $options, $this->getOption(xPDO::OPT_CACHE_HANDLER, $options));
        $options[xPDO::OPT_CACHE_FORMAT] = (integer)$this->getOption('cache_media_sources_format', $options, $this->getOption(xPDO::OPT_CACHE_FORMAT, $options, xPDOCacheManager::CACHE_PHP));
        $options[xPDO::OPT_CACHE_ATTEMPTS] = (integer)$this->getOption('cache_media_sources_attempts', $options, $this->getOption(xPDO::OPT_CACHE_ATTEMPTS, $options, 10));
        $options[xPDO::OPT_CACHE_ATTEMPT_DELAY] = (integer)$this->getOption('cache_media_sources_attempt_delay', $options, $this->getOption(xPDO::OPT_CACHE_ATTEMPT_DELAY, $options, 1000));

        if ($c->prepare() && $c->stmt->execute()) {
            while ($row = $c->stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row && !empty($row['key'])) {
                    $cacheManager->delete($row['key'] . '/source', $options);
                }
            }
        }
    }


    /**
     * Sanitize the specified path
     *
     * @param string $path The path to clean
     *
     * @return string The sanitized path
     */
    public function sanitizePath($path)
    {
        return preg_replace(["/\.*[\/|\\\]/i", "/[\/|\\\]+/i"], [DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $path);
    }


    /**
     * Ensures that the passed path has a / at the end
     *
     * @param string $path
     *
     * @return string The postfixed path
     */
    public function postfixSlash($path)
    {
        $len = strlen($path);
        if (substr($path, $len - 1, $len) != DIRECTORY_SEPARATOR) {
            $path .= DIRECTORY_SEPARATOR;
        }

        return $path;
    }


    /**
     * @param League\Flysystem\AdapterInterface $localAdapter
     * @param string $cache_type ~ memory, persistent or memcached
     */
    protected function loadFlySystem(League\Flysystem\AdapterInterface $localAdapter, $cache_type = 'memory')
    {
        /** @var League\Flysystem\Cached\CachedAdapter $cache */
        switch (strtolower($cache_type)) {
            case 'persistent':
                //no break
            case 'predis':
                // @TODO requires: composer require predis/predis
                $cache = new Predis();
                break;

            case 'memcached':
                $memcached = new \Memcached();
                // @TODO requires config data
                $memcached->addServer('localhost', 11211);

                $cache = new Memcached($memcached, 'storageKey', 300);
                break;

            case 'memory':
                // no break
            default:
                $cache = new CacheStore();
        }
        $this->adapter = new CachedAdapter($localAdapter, $cache);
        $this->filesystem = new Filesystem($this->adapter);
    }


    /**
     * Check that the filename has a file type extension that is allowed
     *
     * @param $filename
     *
     * @return bool
     */
    protected function checkFileType($filename)
    {
        if ($this->getOption('allowedFileTypes')) {
            $allowedFileTypes = $this->getOption('allowedFileTypes');
            $allowedFileTypes = (!is_array($allowedFileTypes)) ? explode(',', $allowedFileTypes) : $allowedFileTypes;
        } else {
            $allowedFiles = $this->xpdo->getOption('upload_files')
                ? explode(',', $this->xpdo->getOption('upload_files')) : [];
            $allowedImages = $this->xpdo->getOption('upload_images')
                ? explode(',', $this->xpdo->getOption('upload_images')) : [];
            $allowedMedia = $this->xpdo->getOption('upload_media')
                ? explode(',', $this->xpdo->getOption('upload_media')) : [];
            $allowedFileTypes = array_unique(array_merge($allowedFiles, $allowedImages, $allowedMedia));
            $allowedFileTypes = array_map('trim', $allowedFileTypes);
            $this->setOption('allowedFileTypes', $allowedFileTypes);
        }

        $ext = strtolower(array_pop(explode('.', $filename)));
        if (!empty($allowedFileTypes) && !in_array($ext, $allowedFileTypes)) {
            $this->addError('path', $this->xpdo->lexicon('file_err_ext_not_allowed', [
                'ext' => $ext,
            ]));

            return false;
        }

        return true;
    }


    /**
     * @param string $path
     * @param string $ext
     * @param array $image_extensions
     * @param array $bases
     * @param array $properties
     *
     * @return array
     */
    protected function buildFileList($path, $ext, $image_extensions, $bases, $properties)
    {
        $file_name = array_pop(explode(DIRECTORY_SEPARATOR, $path));

        $editAction = $this->getEditActionId();
        $canSave = $this->checkPolicy('save');
        $canRemove = $this->checkPolicy('remove');
        $id = rawurlencode(htmlspecialchars_decode($path));

        $cls = [];

        $fullPath = $path;
        if (!empty($bases['pathAbsolute'])) {
            $fullPath = $bases['pathAbsolute'] . ltrim($path, DIRECTORY_SEPARATOR);
        }

        if (!empty($properties['currentFile']) && rawurldecode($properties['currentFile']) == $fullPath . $path && $properties['currentAction'] == $editAction) {
            $cls[] = 'active-node';
        }
        if ($this->hasPermission('file_remove') && $canRemove) {
            $cls[] = 'premove';
        }
        if ($this->hasPermission('file_update') && $canSave) {
            $cls[] = 'pupdate';
        }
        $page = null;
        if ($this->isFileBinary($path)) {
            $page = !empty($editAction)
                ? '?a=' . $editAction . '&file=' . $id . '&wctx=' . $this->ctx->get('key') . '&source=' . $this->get('id')
                : null;
        }
        $visibility = $this->visibility_files ? $this->getVisibility($path) : false;

        $file_list = [
            'id' => $id,
            'sid' => $this->get('id'),
            'text' => $file_name,
            'cls' => implode(' ', $cls),
            'iconCls' => 'icon ' . (
                $visibility == \League\Flysystem\AdapterInterface::VISIBILITY_PRIVATE
                    ? 'icon-eye-slash'
                    : ('icon-file icon-' . $ext)
                ),
            'type' => 'file',
            'leaf' => true,
            'page' => $page,
            'path' => $path,
            'pathRelative' => $path,
            'directory' => $bases['path'],
            'url' => $bases['url'] . $path,
            'urlExternal' => $this->getObjectUrl($path),
            'urlAbsolute' => $bases['urlAbsoluteWithPath'] . ltrim($file_name, DIRECTORY_SEPARATOR),
            'file' => rawurlencode($fullPath . $path),
        ];
        if ($this->visibility_files && $visibility) {
            $file_list['visibility'] = $visibility;
        }
        $file_list['menu'] = [
            'items' => $this->getListFileContextMenu($path, !empty($page), $file_list),
        ];

        // trough tree config we can request a tree without image-preview tooltips, don't do any work if not necessary
        if (!$properties['hideTooltips']) {
            $file_list['qtip'] = '';
            if ($this->isFileImage($path, $image_extensions)) {
                $imageWidth = $this->ctx->getOption('filemanager_image_width', 400);
                $imageHeight = $this->ctx->getOption('filemanager_image_height', 300);
                $preview_image = $this->buildManagerImagePreview($path, $ext, $imageWidth, $imageHeight, $bases, $properties);
                $file_list['qtip'] = '<img src="' . $preview_image['src'] . '" width="' . $preview_image['width'] . '" height="' . $preview_image['height'] . '" alt="' . $path . '" />';
            }
        }

        return $file_list;
    }


    /**
     * @param string $path
     * @param string $ext
     * @param array $image_extensions
     * @param array $bases
     * @param array $properties
     *
     * @return array
     */
    protected function buildFileBrowserViewList($path, $ext, $image_extensions, $bases, $properties)
    {
        $editAction = $this->getEditActionId();

        $page = null;
        if ($this->isFileBinary($path)) {
            $page = !empty($editAction)
                ? '?a=' . $editAction . '&file=' . $path . '&wctx=' . $this->ctx->get('key') . '&source=' . $this->get('id')
                : null;
        }

        $width = $this->ctx->getOption('filemanager_image_width', 800);
        $height = $this->ctx->getOption('filemanager_image_height', 600);
        $preview_image_info = [
            'width' => $width,
            'height' => $height,
        ];

        $thumb_width = $this->ctx->getOption('filemanager_thumb_width', 100);
        $thumb_height = $this->ctx->getOption('filemanager_thumb_height', 80);
        $thumb_image_info = [
            'width' => $thumb_width,
            'height' => $thumb_height,
        ];

        $preview = 0;
        if ($this->isFileImage($path, $image_extensions)) {
            $preview = 1;
            $preview_image_info = $this->buildManagerImagePreview($path, $ext, $width, $height, $bases, $properties);
            $thumb_image_info = $this->buildManagerImagePreview($path, $ext, $thumb_width, $thumb_height, $bases, $properties);
        }

        $visibility = $this->visibility_files ? $this->getVisibility($path) : false;

        try {
            $lastmod = $this->filesystem->getTimestamp($path);
            $size = $this->filesystem->getSize($path);
        } catch (Exception $e) {
            $lastmod = 0;
            $size = 0;
        }
        $file_list = [
            'id' => $path,
            'sid' => $this->get('id'),
            'name' => array_pop(explode(DIRECTORY_SEPARATOR, $path)),
            'cls' => 'icon-' . $ext,
            // preview
            'preview' => $preview,
            'image' => $preview_image_info['src'],
            'image_width' => $preview_image_info['width'],
            'image_height' => $preview_image_info['height'],
            // thumb
            'thumb' => $thumb_image_info['src'],
            'thumb_width' => $thumb_image_info['width'],
            'thumb_height' => $thumb_image_info['height'],

            'url' => $path,
            'relativeUrl' => ltrim($path, DIRECTORY_SEPARATOR),
            'fullRelativeUrl' => rtrim($bases['url']) . ltrim($path, DIRECTORY_SEPARATOR),
            'ext' => $ext,
            'pathname' => $path,
            'pathRelative' => rawurlencode($path),

            'lastmod' => $lastmod,
            'disabled' => false,
            'leaf' => true,
            'page' => $page,
            'size' => $size,
            'menu' => $this->getListFileContextMenu($path, !empty($page)),
        ];
        if ($this->visibility_files && $visibility) {
            $file_list['visibility'] = $visibility;
        }
        $file_list['menu'] = $this->getListFileContextMenu($path, !empty($page));

        return $file_list;
    }


    /**
     * @param array $properties
     *
     * @return array|mixed
     */
    protected function getSkipFilesArray($properties = [])
    {
        $skipFiles = $this->getOption('skipFiles', $properties, '.svn,.git,_notes,nbproject,.idea,.DS_Store');
        if ($this->xpdo->getParser()) {
            $this->xpdo->parser->processElementTags('', $skipFiles, true, true);
        }
        $skipFiles = array_map('trim', explode(',', $skipFiles));
        $skipFiles[] = '.';
        $skipFiles[] = '..';

        return $skipFiles;
    }


    /**
     * @param array $properties
     *
     * @return array|mixed|string
     */
    protected function getAllowedExtensionsArray($properties = [])
    {
        $allowedExtensions = $this->getOption('allowedFileTypes', $properties, '');
        if (is_string($allowedExtensions)) {
            if (empty($allowedExtensions)) {
                $allowedExtensions = [];
            } else {
                $allowedExtensions = explode(',', $allowedExtensions);
            }
        }

        return $allowedExtensions;
    }


    /**
     * @return array
     */
    protected function getExtJSDirClasses()
    {

        $canSave = $this->checkPolicy('save');
        $canRemove = $this->checkPolicy('remove');
        $canCreate = $this->checkPolicy('create');

        $cls[] = 'folder';
        if ($this->hasPermission('directory_chmod') && $canSave) {
            $cls[] = 'pchmod';
        }
        if ($this->hasPermission('directory_create') && $canCreate) {
            $cls[] = 'pcreate';
        }
        if ($this->hasPermission('directory_remove') && $canRemove) {
            $cls[] = 'premove';
        }
        if ($this->hasPermission('directory_update') && $canSave) {
            $cls[] = 'pupdate';
        }
        if ($this->hasPermission('file_upload') && $canCreate) {
            $cls[] = 'pupload';
        }
        if ($this->hasPermission('file_create') && $canCreate) {
            $cls[] = 'pcreate';
        }

        return $cls;
    }


    /**
     * Get the context menu items for a specific dir object in the list view
     *
     * @return array
     */
    protected function getListDirContextMenu()
    {
        $canSave = $this->checkPolicy('save');
        $canRemove = $this->checkPolicy('remove');
        $canCreate = $this->checkPolicy('create');

        $menu = [];
        if ($this->hasPermission('directory_create') && $canCreate) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_folder_create_here'),
                'handler' => 'this.createDirectory',
            ];
        }
        if ($this->hasPermission('directory_update') && $canSave) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('rename'),
                'handler' => 'this.renameDirectory',
            ];
        }
        if ($this->visibility_dirs && $this->hasPermission('directory_chmod') && $canSave) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_folder_visibility'),
                'handler' => 'this.setVisibility',
            ];
        }
        $menu[] = [
            'text' => $this->xpdo->lexicon('directory_refresh'),
            'handler' => 'this.refreshActiveNode',
        ];
        if ($this->hasPermission('file_upload') && $canCreate) {
            $menu[] = '-';
            $menu[] = [
                'text' => $this->xpdo->lexicon('upload_files'),
                'handler' => 'this.uploadFiles',
            ];
        }
        if ($this->hasPermission('file_create') && $canCreate) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_create'),
                'handler' => 'this.createFile',
            ];
            $menu[] = [
                'text' => $this->xpdo->lexicon('quick_create_file'),
                'handler' => 'this.quickCreateFile',
            ];
        }
        if ($this->hasPermission('directory_remove') && $canRemove) {
            $menu[] = '-';
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_folder_remove'),
                'handler' => 'this.removeDirectory',
            ];
        }

        return $menu;
    }


    /**
     * Get the context menu items for a specific file object in the list view
     *
     * @param string $path
     * @param bool $editable
     * @param array $data
     *
     * @return array
     */
    protected function getListFileContextMenu($path, $editable = true, $data = [])
    {
        $canSave = $this->checkPolicy('save');
        $canRemove = $this->checkPolicy('remove');
        $canView = $this->checkPolicy('view');
        $canOpen = !empty($data['urlExternal']) &&
            (empty($data['visibility']) || $data['visibility'] == \League\Flysystem\AdapterInterface::VISIBILITY_PUBLIC);

        $menu = [];
        if ($this->hasPermission('file_update') && $canSave) {
            if ($editable) {
                $menu[] = [
                    'text' => $this->xpdo->lexicon('file_edit'),
                    'handler' => 'this.editFile',
                ];
                $menu[] = [
                    'text' => $this->xpdo->lexicon('quick_update_file'),
                    'handler' => 'this.quickUpdateFile',
                ];
            }
            if ($canOpen) {
                $menu[] = [
                    'text' => $this->xpdo->lexicon('file_open'),
                    'handler' => 'this.openFile',
                ];
            }
            $menu[] = [
                'text' => $this->xpdo->lexicon('rename'),
                'handler' => 'this.renameFile',
            ];
            if ($this->visibility_files && $canSave) {
                $menu[] = [
                    'text' => $this->xpdo->lexicon('file_folder_visibility'),
                    'handler' => 'this.setVisibility',
                ];
            }
            $menu[] = '-';
        }
        if ($this->hasPermission('file_view') && $canView) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_download'),
                'handler' => 'this.downloadFile',
            ];
        }
        if ($this->hasPermission('file_unpack') && $canView && array_pop(explode('.', $path)) === 'zip') {
            if ($this instanceof modFileMediaSource) {
                $menu[] = [
                    'text' => $this->xpdo->lexicon('file_download_unzip'),
                    'handler' => 'this.unpackFile',
                ];
            }
        }
        if ($this->hasPermission('file_remove') && $canRemove) {
            if (!empty($menu)) $menu[] = '-';
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_remove'),
                'handler' => 'this.removeFile',
            ];
        }

        return $menu;
    }


    /**
     * @param string $path
     * @param string $ext
     * @param int $width
     * @param int $height
     * @param array $bases
     * @param array $properties
     *
     * @return array
     */
    protected function buildManagerImagePreview($path, $ext, $width, $height, $bases, $properties = [])
    {
        $size = $this->getImageDimensions($path, $ext);
        if (is_array($size) && $size['width'] > 0 && $size['height'] > 0) {
            if ($ext == 'svg') {
                $size['src'] = $bases['urlAbsolute'] . ltrim($path, DIRECTORY_SEPARATOR);

                return $size;
            }

            try {
                $timestamp = $this->filesystem->getTimestamp($path);
            } catch (Exception $E) {
                $timestamp = 0;
            }

            if ($bases['pathIsRelative']) {
                $absolute_path = $bases['pathAbsolute'] . $path;
            } else {
                $absolute_path = $path;
            }
            if (function_exists('exif_read_data')) {
                $exif = exif_read_data($absolute_path);
                if (!empty($exif) && $exif['Orientation'] >= 5) {
                    // This image was rotated
                    $new_width = $size['height'];
                    $new_height = $size['width'];
                    $size['width'] = $new_width;
                    $size['height'] = $new_height;
                }
            }
            // get original image size for proportional scaling
            if ($size['width'] > $size['height']) {
                // landscape
                $imageQueryWidth = $size['width'] >= $width ? $width : $size['width'];
                $imageQueryHeight = 0;
                $width = $imageQueryWidth;
                $height = round($size['height'] * ($imageQueryWidth / $size['width']));
            } else {
                // portrait or square
                $imageQueryWidth = 0;
                $imageQueryHeight = $size['height'] >= $height ? $height : $size['height'];
                $width = round($size['width'] * ($imageQueryHeight / $size['height']));
                $height = $imageQueryHeight;
            }

            $imageQuery = http_build_query([
                'src' => rawurlencode($path),
                'w' => $imageQueryWidth,
                'h' => $imageQueryHeight,
                'HTTP_MODAUTH' => $this->xpdo->user->getUserToken($this->xpdo->context->get('key')),
                'f' => $this->getOption('thumbnailType', $properties, 'png'),
                'q' => $this->getOption('thumbnailQuality', $properties, 90),
                'wctx' => $this->ctx->get('key'),
                'source' => $this->get('id'),
                't' => $timestamp,
                'ar' => 'x'
            ]);
            $image = $this->ctx->getOption('connectors_url', MODX_CONNECTORS_URL) . 'system/phpthumb.php?' . $imageQuery;
        }

        return [
            'src' => $image,
            'width' => $width,
            'height' => $height,
        ];
    }


    /**
     * @param $path
     * @param $ext
     *
     * @return array|bool
     */
    protected function getImageDimensions($path, $ext)
    {
        $file_size = false;
        try {
            if ($ext == 'svg') {
                $svgString = $this->filesystem->read($path);
                preg_match('/(<svg[^>]*\swidth=")([\d\.]+)([a-z]*)"/si', $svgString, $svgWidth);
                preg_match('/(<svg[^>]*\sheight=")([\d\.]+)([a-z]*)"/si', $svgString, $svgHeight);
                preg_match('/(<svg[^>]*\sviewBox=")([\d\.]+(?:,|\s)[\d\.]+(?:,|\s)([\d\.]+)(?:,|\s)([\d\.]+))"/si', $svgString, $svgViewbox);
                if (!empty($svgViewbox)) {
                    // get width and height from viewbox attribute
                    $width = round($svgViewbox[3]);
                    $height = round($svgViewbox[4]);
                } elseif (!empty($svgWidth) && !empty($svgHeight)) {
                    // get width and height from width and height attributes
                    $width = round($svgWidth[2]);
                    $height = round($svgHeight[2]);
                } else {
                    return false;
                }
                $file_size = [
                    'width' => $width,
                    'height' => $height,
                ];
            } else {
                /** @var League\Flysystem\Handler $file */
                $file = $this->filesystem->get($path);
                $size = @getimagesize($this->getBasePath() . $file->getPath());
                if (is_array($size)) {
                    // make this human readable
                    $file_size = [
                        'width' => $size[0],
                        'height' => $size[1],
                    ];
                }
            }
        } catch (Exception $e) {
            // pass
        }

        return $file_size;
    }


    /**
     * Tells if a file is a binary file (some sort of text file) or not.
     *
     * @param string $file
     *
     * @return boolean True if a binary file.
     */
    protected function isFileBinary($file)
    {
        try {
            $mime = $this->filesystem->getMimetype($file);

            return strpos($mime, 'text') === 0;
        } catch (Exception $e) {
            // pass
        }

        return false;
    }


    /**
     * Tells if a file is an image or not.
     *
     * @param string $file
     * @param array $image_extensions
     *
     * @return boolean True if a binary file.
     */
    protected function isFileImage($file, $image_extensions = [])
    {
        try {
            $mime = $this->filesystem->getMimetype($file);
            $ext = strtolower(array_pop(explode(DIRECTORY_SEPARATOR, trim($file, DIRECTORY_SEPARATOR))));

            return strpos($mime, 'image') === 0 || in_array($ext, array_map('strtolower', $image_extensions));
        } catch (Exception $e) {
            // pass
        }

        return false;
    }
}
