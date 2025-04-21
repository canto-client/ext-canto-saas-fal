<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Fairway\CantoSaasFal\Resource;

use Fairway\CantoSaasFal\Resource\Event\BeforeMdcUrlGenerationEvent;
use Fairway\CantoSaasFal\Resource\Repository\CantoRepository;
use Fairway\CantoSaasFal\Utility\CantoUtility;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Imaging\ImageDimension;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Processing\TaskInterface;

/**
 * todo: add documentation
 */
final class MdcUrlGenerator
{
    // get image as a square, formatted as -B<image-size>
    public const BOXED = '-B';
    // get image scaled down to width+height, formatted as -S<width>x<height>
    public const SCALED = '-S';
    // get image formatted into a provided file extension, formatted as -F<FILE_EXT> [JPG, WEBP, PNG, TIF, GIF, JP2 (JPG2000)]
    public const FORMATTED = '-F';
    // get image cropped to an area, formatted as -C<width>x<height>,<x>,<y>
    public const CROPPED = '-C';

    private CantoRepository $cantoRepository;
    private EventDispatcher $eventDispatcher;

    public function __construct(CantoRepository $cantoRepository, EventDispatcher $eventDispatcher)
    {
        $this->cantoRepository = $cantoRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function generateMdcUrl(TaskInterface $task): string
    {
        $transformedConfiguration = $this->transformConfiguration($task);

        return $this->cantoRepository->generateMdcUrl($task->getSourceFile()->getIdentifier()) . $this->addOperationToMdcUrl($transformedConfiguration);
    }

    /**
     * @return array{width: int, height: int}
     */
    public function resolveImageWidthHeight(TaskInterface $task): array
    {
        $configuration = $this->transformConfiguration($task);
        $width = min($configuration['width'], $processingConfiguration['maxWidth'] ?? PHP_INT_MAX);
        $height = min($configuration['height'], $processingConfiguration['maxHeight'] ?? PHP_INT_MAX);
        return [
            'width' => (int)$width,
            'height' => (int)$height,
        ];
    }

    /**
     * @return array{width: int, height: int, scale: float}
     */
    private function getMasterImageDimensions(File $file): array
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($file->getIdentifier());
        $identifier = CantoUtility::getIdFromCombinedIdentifier($file->getIdentifier());
        $fileData = $this->cantoRepository->getFileDetails($scheme, $identifier);

        $width = $fileData['width'] ?? $file->getProperty('width');
        $height = $fileData['height'] ?? $file->getProperty('height');

        $masterImageSize = $file->getStorage()->getConfiguration()['masterImageSize'];
        $scale = min(1, $masterImageSize / $width, $masterImageSize / $height);

        return [
            'width' => $scale * $width,
            'height' => $scale * $height,
            'scale' => $scale,
        ];
    }

    /**
     * @param array{width: int, height: int, size?: int, x?: int, y?: int, format?: string, crop?: ?Area, resizedCropped?: array{width: int, height: int, offsetLeft: int, offsetTop: int}} $configuration
     * @return string
     */
    public function addOperationToMdcUrl(array $configuration): string
    {
        // @todo are there alternatives than Area ?
        $crop = ($configuration['crop'] ?? null) instanceof Area;
        $scaleString = '';
        $formatString = '';
        $cropString = '';
        if (isset($configuration['size'])) {
            $scaleString = self::BOXED . $configuration['size'];
        }
        if (!$scaleString && isset($configuration['width']) && isset($configuration['height'])) {
            $scaleString = self::SCALED . (int)$configuration['width'] . 'x' . (int)$configuration['height'];
        }
        if (isset($configuration['format'])) {
            $formatString = self::FORMATTED . $configuration['format'];
        }
        if ($crop) {
            $croppingArea = $configuration['resizedCropped'];
            $cropString = self::CROPPED . $croppingArea['width'] . 'x' . $croppingArea['height'];
            $cropString .= ',' . (int)$croppingArea['offsetLeft'] . ',' . (int)$croppingArea['offsetTop'];
        }
        $event = new BeforeMdcUrlGenerationEvent($configuration, $scaleString, $cropString, $formatString, true);

        return $this->eventDispatcher->dispatch($event)->getMdcUrl();
    }

    /**
     * @param array{width: ?int, height: ?int, minWidth: numeric|null, minHeight: numeric|null, maxWidth: numeric|null, maxHeight: numeric|null, crop: ?Area} $configuration
     * @return array{width: int, height: int, size?: ?int, x?: ?int, y?: ?int, format?: ?string, crop: ?Area, minWidth?: numeric|null, minHeight?: numeric|null, maxWidth?: numeric|null, maxHeight?: numeric|null}
     */
    private function transformConfiguration(TaskInterface $task): array
    {
        $configuration = $task->getConfiguration();
        if (!empty($configuration['width']) && !empty($configuration['height'])) {
            $configuration['height'] = (int)$configuration['height'];
            $configuration['width'] = (int)$configuration['width'];

            return $configuration;
        }
        $imageDimensions = ImageDimension::fromProcessingTask($task);
        $configuration['height'] = $imageDimensions->getHeight() ?: $configuration['height'];
        $configuration['width'] = $imageDimensions->getWidth() ?: $configuration['width'];
        $masterImageDimension = $this->getMasterImageDimensions($task->getSourceFile());
        $configuration['height'] = $configuration['height'] ?? $configuration['maxHeight'] ?? $masterImageDimension['height'] ?? 0;
        $configuration['width'] = $configuration['width'] ?? $configuration['maxWidth'] ?? $masterImageDimension['width'] ?? 0;
        if (($configuration['crop'] ?? null) instanceof Area) {
            $configuration['height'] = min($configuration['height'], $configuration['crop']->getHeight());
            $configuration['width'] = min($configuration['width'], $configuration['crop']->getWidth());
            $configuration['resizedCropped'] = [
                'width' => (int)($configuration['crop']->getWidth() * $masterImageDimension['scale']),
                'height' => (int)($configuration['crop']->getHeight() * $masterImageDimension['scale']),
                'offsetLeft' => (int)($configuration['crop']->getOffsetLeft() * $masterImageDimension['scale']),
                'offsetTop' => (int)($configuration['crop']->getOffsetTop() * $masterImageDimension['scale']),
            ];
        }
        $configuration['height'] = (int)$configuration['height'];
        $configuration['width'] = (int)$configuration['width'];
        if ($configuration['width'] === $configuration['height']) {
            $configuration['size'] = $configuration['width'];
        }
        if (isset($configuration['fileExtension'])) {
            $configuration['format'] = strtoupper($configuration['fileExtension']);
        }

        return $configuration;
    }
}
