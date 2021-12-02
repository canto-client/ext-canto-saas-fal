<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Ecentral\CantoSaasFal\Resource\Driver;

use Ecentral\CantoSaasApiClient\Endpoint\Authorization\AuthorizationFailedException;
use Ecentral\CantoSaasApiClient\Http\LibraryTree\GetTreeRequest;
use Ecentral\CantoSaasApiClient\Http\LibraryTree\ListAlbumContentRequest;
use Ecentral\CantoSaasApiClient\Http\LibraryTree\SearchFolderRequest;
use Ecentral\CantoSaasFal\Resource\MdcUrlGenerator;
use Ecentral\CantoSaasFal\Resource\Repository\CantoRepository;
use Ecentral\CantoSaasFal\Utility\CantoUtility;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class CantoDriver extends AbstractReadOnlyDriver
{
    public const DRIVER_NAME = 'Canto';

    public const ROOT_FOLDER = 'ROOT';

    protected CantoRepository $cantoRepository;

    protected string $rootFolderIdentifier;

    protected bool $validCantoConfiguration;

    /** @var string[] */
    public static array $transientCachedFiles = [];

    private ?MdcUrlGenerator $mdcUrlGenerator = null;

    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);
        $this->capabilities = ResourceStorage::CAPABILITY_BROWSABLE;
        $this->rootFolderIdentifier = $this->buildRootFolderIdentifier();
    }

    public function processConfiguration()
    {
        $this->validCantoConfiguration = is_int($this->storageUid)
            && $this->storageUid > 0
            && strlen($this->configuration['cantoName'] ?? '') > 0
            && strlen($this->configuration['cantoDomain'] ?? '') > 0
            && strlen($this->configuration['appId'] ?? '') > 0
            && strlen($this->configuration['appSecret'] ?? '') > 0;
    }

    public function initialize()
    {
        // The check is necessary to prevent an error thrown in Maintenance Admin Tool -> Remove Temporary Assets
        if ($this->validCantoConfiguration && GeneralUtility::getContainer()->has(CantoRepository::class)) {
            $this->cantoRepository = GeneralUtility::makeInstance(CantoRepository::class);
            try {
                $this->cantoRepository->initialize($this->storageUid, $this->configuration);
            } catch (AuthorizationFailedException $e) {
                // TODO Show error message in TYPO3 Backend.
            }
        }
        $this->mdcUrlGenerator = GeneralUtility::makeInstance(MdcUrlGenerator::class);
    }

    /**
     * @param int $capabilities
     */
    public function mergeConfigurationCapabilities($capabilities): int
    {
        $this->capabilities &= $capabilities;
        return $this->capabilities;
    }

    public function getRootLevelFolder(): string
    {
        return $this->rootFolderIdentifier;
    }

    public function getDefaultFolder(): string
    {
        return $this->rootFolderIdentifier;
    }

    /**
     * @param string $fileIdentifier
     * @throws FolderDoesNotExistException
     */
    public function getParentFolderIdentifierOfIdentifier($fileIdentifier): string
    {
        if (!$fileIdentifier) {
            return '';
        }
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($fileIdentifier);
        $explicitFileIdentifier = CantoUtility::getIdFromCombinedIdentifier($fileIdentifier);
        if ($explicitFileIdentifier === self::ROOT_FOLDER) {
            return $fileIdentifier;
        }

        if (CantoUtility::isFolder($scheme)) {
            $result = $this->cantoRepository->getFolderDetails($scheme, $explicitFileIdentifier);
            $pathIds = explode('/', $result['idPath']);
            if (count($pathIds) === 1) {
                return $this->rootFolderIdentifier;
            }
            // Remove current folder/album id.
            array_pop($pathIds);
            // The parent folder is always of scheme folder because albums can only contain files.
            return CantoUtility::buildCombinedIdentifier(CantoUtility::SCHEME_FOLDER, array_pop($pathIds));
        }

        // TODO Check if this method is used for files.
        return '';
    }

    /**
     * @param string $identifier
     */
    public function getPublicUrl($identifier): ?string
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($identifier);
        $fileIdentifier = CantoUtility::getIdFromCombinedIdentifier($identifier);
        $useMdc = CantoUtility::isMdcActivated($identifier);
        $fileData = $this->cantoRepository->getFileDetails($scheme, $fileIdentifier, $useMdc);
        if ($useMdc && $this->mdcUrlGenerator) {
            $url = $this->cantoRepository->generateMdcUrl($fileIdentifier);
            $url .= $this->mdcUrlGenerator->addOperationToMdcUrl([
                'width' => (int)$fileData['width'],
                'height' => (int)$fileData['height'],
            ]);
            return rawurldecode($url);
        }
        if (!empty($fileData['url']['directUrlOriginal'])) {
            return rawurldecode($fileData['url']['directUrlOriginal']);
        }
        return null;
    }

    /**
     * @param string $fileIdentifier
     */
    public function fileExists($fileIdentifier): bool
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($fileIdentifier);
        if (CantoUtility::isFolder($scheme)) {
            return false;
        }
        $explicitFileIdentifier = CantoUtility::getIdFromCombinedIdentifier($fileIdentifier);
        $result = $this->cantoRepository->getFileDetails(
            $scheme,
            $explicitFileIdentifier,
            CantoUtility::isMdcActivated($fileIdentifier)
        );
        return !empty($result);
    }

    /**
     * @param string $folderIdentifier
     */
    public function folderExists($folderIdentifier): bool
    {
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        if ($explicitFolderIdentifier === self::ROOT_FOLDER) {
            return true;
        }

        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        try {
            $result = $this->cantoRepository->getFolderDetails($scheme, $explicitFolderIdentifier);
        } catch (FolderDoesNotExistException $e) {
            return false;
        }
        return !empty($result);
    }

    /**
     * @param string $folderIdentifier
     * @throws FolderDoesNotExistException
     */
    public function isFolderEmpty($folderIdentifier): bool
    {
        return ($this->countFilesInFolder($folderIdentifier) + $this->countFoldersInFolder($folderIdentifier)) === 0;
    }

    /**
     * @param string $fileIdentifier
     * @param string $hashAlgorithm The hash algorithm to use
     */
    public function hash($fileIdentifier, $hashAlgorithm): string
    {
        return hash($hashAlgorithm, $fileIdentifier);
    }

    /**
     * @param string $fileIdentifier
     */
    public function getFileContents($fileIdentifier): string
    {
        $publicUrl = $this->getPublicUrl($fileIdentifier);
        if ($publicUrl !== '') {
            return GeneralUtility::getUrl($publicUrl);
        }
        return '';
    }

    /**
     * @param string $fileName
     * @param string $folderIdentifier
     */
    public function fileExistsInFolder($fileName, $folderIdentifier): bool
    {
        // TODO: Implement fileExistsInFolder() method.
        return true;
    }

    /**
     * @param string $folderName
     * @param string $folderIdentifier
     */
    public function folderExistsInFolder($folderName, $folderIdentifier): bool
    {
        if ($folderName === $folderIdentifier) {
            return true;
        }
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        try {
            $folderData = $this->cantoRepository->getFolderDetails($scheme, $explicitFolderIdentifier);
        } catch (FolderDoesNotExistException $e) {
            return false;
        }
        $subFolders = str_getcsv($folderData['idPath'], '/');
        return in_array(
            CantoUtility::getIdFromCombinedIdentifier($folderName),
            $subFolders,
            false
        );
    }

    /**
     * @param string $fileIdentifier
     * @param bool $writable Set this to FALSE if you only need the file for read
     *                       operations. This might speed up things, e.g. by using
     *                       a cached local version. Never modify the file if you
     *                       have set this flag!
     */
    public function getFileForLocalProcessing($fileIdentifier, $writable = true): string
    {
        return $this->cantoRepository->getFileForLocalProcessing($fileIdentifier);
    }

    /**
     * @param string $identifier
     */
    public function getPermissions($identifier): array
    {
        return [
            'r' => true,
            'w' => false,
        ];
    }

    /**
     * @param string $identifier
     */
    public function dumpFileContents($identifier): void
    {
        echo $this->getFileContents($identifier);
    }

    /**
     * @param string $folderIdentifier
     * @param string $identifier identifier to be checked against $folderIdentifier
     */
    public function isWithin($folderIdentifier, $identifier): bool
    {
        /*
         * Ensure that the given identifiers are valid. Do not throw an exception,
         * because the processing folder is currently handed to this method, even
         * if it is configured for another driver.
         * See https://forge.typo3.org/issues/94645
         */
        if (
            !CantoUtility::isValidCombinedIdentifier($folderIdentifier)
            || !CantoUtility::isValidCombinedIdentifier($identifier)
        ) {
            return false;
        }

        $schemeToCheck = CantoUtility::getSchemeFromCombinedIdentifier($identifier);
        if (CantoUtility::isFolder($schemeToCheck)) {
            return $this->folderExistsInFolder($folderIdentifier, $identifier);
        }

        return $this->fileExistsInFolder($folderIdentifier, $identifier);
    }

    /**
     * @param string $fileIdentifier
     * @param array $propertiesToExtract Array of properties which are being extracted
     *                                   If empty all will be extracted
     * @throws FolderDoesNotExistException
     */
    public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = []): array
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($fileIdentifier);
        if (CantoUtility::isFolder($scheme)) {
            return $this->getFolderInfoByIdentifier($fileIdentifier);
        }

        $folders = [];
        $explicitFileIdentifier = CantoUtility::getIdFromCombinedIdentifier($fileIdentifier);
        $result = $this->cantoRepository->getFileDetails(
            $scheme,
            $explicitFileIdentifier,
            CantoUtility::isMdcActivated($fileIdentifier)
        );
        foreach ($result['relatedAlbums'] ?? [] as $album) {
            $folders[] = CantoUtility::buildCombinedIdentifier($album['scheme'], $album['id']);
        }

        $data = [
            'size' => $result['default']['Size'],
            'atime' => time(),
            'mtime' => CantoUtility::buildTimestampFromCantoDate($result['default']['Date modified']),
            'ctime' => CantoUtility::buildTimestampFromCantoDate($result['default']['Date uploaded']),
            'mimetype' => $result['default']['Content Type'] ?? '',
            'name' => $result['name'],
            'extension' => PathUtility::pathinfo($result['name'], PATHINFO_EXTENSION),
            'identifier' => $fileIdentifier,
            'identifier_hash' => $this->hashIdentifier($fileIdentifier),
            'storage' => $this->storageUid,
            'folder_hash' => '',
            'folder_identifiers' => $folders,
        ];
        if (!$propertiesToExtract) {
            return $data;
        }
        $properties = [];
        foreach ($propertiesToExtract as $item) {
            $properties[$item] = $data[$item];
        }
        return $properties;
    }

    /**
     * @param string $folderIdentifier In the case of the LocalDriver, this is the (relative) path to the file.
     * @throws FolderDoesNotExistException
     */
    public function getFolderInfoByIdentifier($folderIdentifier): array
    {
        $now = time();
        $rootFolder = [
            'identifier' => 'folder#' . self::ROOT_FOLDER,
            'name' => 'Canto',
            'mtime' => $now,
            'ctime' => $now,
            'storage' => $this->storageUid
        ];
        if (!$folderIdentifier || $folderIdentifier === self::ROOT_FOLDER) {
            return $rootFolder;
        }
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        if ($explicitFolderIdentifier === self::ROOT_FOLDER) {
            return $rootFolder;
        }
        $scheme =CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        $result = $this->cantoRepository->getFolderDetails($scheme, $explicitFolderIdentifier);
        // TODO Find solution how to handle equal folder and album names.
        $folderName = sprintf('F: %s', $result['name']);
        if ($scheme === CantoUtility::SCHEME_ALBUM) {
            $folderName = sprintf('A: %s', $result['name']);
        }

        return [
            'identifier' => $folderIdentifier,
            'name' => $folderName,
            'mtime' => CantoUtility::buildTimestampFromCantoDate($result['time']),
            'ctime' => CantoUtility::buildTimestampFromCantoDate($result['created']),
            'storage' => $this->storageUid
        ];
    }

    /**
     * @param string $fileName
     * @param string $folderIdentifier
     */
    public function getFileInFolder($fileName, $folderIdentifier): string
    {
        // TODO: Implement getFileInFolder() method.
        return $fileName;
    }

    /**
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $filenameFilterCallbacks callbacks for filtering the items
     * @param string $sort Property name used to sort the items.
     *                     Among them may be: '' (empty, no sorting), name,
     *                     fileext, size, tstamp and rw.
     *                     If a driver does not support the given property, it
     *                     should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     */
    public function getFilesInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $filenameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ): array {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        if ($scheme === CantoUtility::SCHEME_FOLDER || $explicitFolderIdentifier === self::ROOT_FOLDER) {
            // There are no files in folders, just other files and albums.
            return [];
        }
        $files = [];
        $sortBy = $this->mapSortBy($sort);
        $sortDirection = $sortRev ? ListAlbumContentRequest::SORT_DIRECTION_DESC
            : ListAlbumContentRequest::SORT_DIRECTION_ASC;
        $limit = $numberOfItems > 0 ? min($numberOfItems, 1000) : 1000;
        // TODO Check if there are more that 1000 files and make multiple requests if needed.
        $results = $this->cantoRepository->getFilesInFolder(
            $explicitFolderIdentifier,
            $start,
            $limit,
            $sortBy,
            $sortDirection
        );
        foreach ($results as $result) {
            $fileIdentifier = CantoUtility::buildCombinedIdentifier($result['scheme'], $result['id']);
            $this->cantoRepository->setFileCache($fileIdentifier, $result);
            $files[] = $fileIdentifier;
        }
        return $files;
    }

    /**
     * @param string $folderName The name of the target folder
     * @param string $folderIdentifier
     */
    public function getFolderInFolder($folderName, $folderIdentifier): string
    {
        // TODO: Implement getFolderInFolder() method.
        return $folderName;
    }

    /**
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks The method callbacks to use for filtering the items
     * @param string $sort Property name used to sort the items.
     *                     Among them may be: '' (empty, no sorting), name,
     *                     fileext, size, tstamp and rw.
     *                     If a driver does not support the given property, it
     *                     should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     * @throws FolderDoesNotExistException
     */
    public function getFoldersInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $folderNameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ): array {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        if ($scheme === CantoUtility::SCHEME_ALBUM) {
            // Albums contain only files, not folders.
            return [];
        }
        $folders = [];
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        $sortBy = GetTreeRequest::SORT_BY_NAME;
        $sortDirection = $sortRev ? GetTreeRequest::SORT_DIRECTION_DESC : GetTreeRequest::SORT_DIRECTION_ASC;
        $folderTree = $this->cantoRepository->getFolderIdentifierTree($sortBy, $sortDirection);
        if ($explicitFolderIdentifier !== self::ROOT_FOLDER) {
            $folderInformation = $this->cantoRepository->getFolderDetails($scheme, $explicitFolderIdentifier);
            $idPathSegments = str_getcsv($folderInformation['idPath'], '/');
            $lastSegmentIndex = count($idPathSegments) - 1;
            array_walk(
                $idPathSegments,
                function (string &$folderIdentifier, int $key, $scheme) use ($lastSegmentIndex, $folderInformation) {
                    if ($key === $lastSegmentIndex) {
                        $scheme = $folderInformation['scheme'];
                    }
                    $folderIdentifier = CantoUtility::buildCombinedIdentifier($scheme, $folderIdentifier);
                },
                CantoUtility::SCHEME_FOLDER
            );
            $idPath = implode('/', $idPathSegments);
            try {
                $folderTree = ArrayUtility::getValueByPath($folderTree, $idPath);
            } catch (MissingArrayPathException $e) {
            }
        }
        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($folderTree), \RecursiveIteratorIterator::SELF_FIRST);
            $folderTree = iterator_to_array($iterator, true);
        }

        // $c is the counter for how many items we still have to fetch (-1 is unlimited)
        $c = $numberOfItems > 0 ? $numberOfItems : -1;
        foreach (array_keys($folderTree) as $identifier) {
            if ($c === 0) {
                break;
            }
            if ($start > 0) {
                $start--;
            } else {
                $folders[$identifier] = $identifier;
                --$c;
            }
        }
        return $folders;
    }

    /**
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $filenameFilterCallbacks callbacks for filtering the items
     */
    public function countFilesInFolder($folderIdentifier, $recursive = false, array $filenameFilterCallbacks = []): int
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        if ($scheme === CantoUtility::SCHEME_FOLDER || $explicitFolderIdentifier === self::ROOT_FOLDER) {
            // Folders can not have files, just other folders and albums.
            return 0;
        }
        return $this->cantoRepository->countFilesInFolder($explicitFolderIdentifier);
    }

    /**
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks callbacks for filtering the items
     * @throws FolderDoesNotExistException
     */
    public function countFoldersInFolder(
        $folderIdentifier,
        $recursive = false,
        array $folderNameFilterCallbacks = []
    ): int {
        return count($this->getFoldersInFolder(
            $folderIdentifier,
            0,
            0,
            $recursive,
            $folderNameFilterCallbacks
        ));
    }

    /**
     * @param string $identifier
     */
    public function hashIdentifier($identifier): string
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($identifier);
        if (CantoUtility::isFolder($scheme)) {
            $identifier = $this->canonicalizeAndCheckFolderIdentifier($identifier);
        }
        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        return $this->hash($identifier, 'sha1');
    }

    protected function mapSortBy(string $sortBy): string
    {
        switch ($sortBy) {
            case 'name':
                return SearchFolderRequest::SORT_BY_NAME;
            case 'fileext':
                return SearchFolderRequest::SORT_BY_SCHEME;
            case 'size':
                return SearchFolderRequest::SORT_BY_SIZE;
        }
        return SearchFolderRequest::SORT_BY_TIME;
    }

    protected function buildRootFolderIdentifier(): string
    {
        $rootFolderScheme = $this->configuration['rootFolderScheme'] ?? CantoUtility::SCHEME_FOLDER;
        $rootFolder = $this->configuration['rootFolder'] ?? self::ROOT_FOLDER;
        if (CantoUtility::isFolder($rootFolderScheme) && $rootFolder !== '') {
            return CantoUtility::buildCombinedIdentifier(
                $rootFolderScheme,
                $rootFolder
            );
        }
        return CantoUtility::buildCombinedIdentifier(
            CantoUtility::SCHEME_FOLDER,
            self::ROOT_FOLDER
        );
    }

    /**
     * @param string $filePath The file path (most times filePath)
     */
    protected function canonicalizeAndCheckFilePath($filePath): string
    {
        return $filePath;
    }

    /**
     * @param string $fileIdentifier The file Identifier
     */
    protected function canonicalizeAndCheckFileIdentifier($fileIdentifier): string
    {
        return $fileIdentifier;
    }

    /**
     * @param string $folderIdentifier The folder identifier
     */
    protected function canonicalizeAndCheckFolderIdentifier($folderIdentifier): string
    {
        return $folderIdentifier;
    }

    /**
     * Transient File-Cache cleanup
     * @see https://review.typo3.org/#/c/36446/
     */
    public function __destruct()
    {
        foreach (self::$transientCachedFiles as $cachedFile) {
            if (file_exists($cachedFile)) {
                unlink($cachedFile);
            }
        }
    }
}
