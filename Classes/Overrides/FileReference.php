<?php

namespace SUDHAUS7\ResponsivePicture\Overrides;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class FileReference
 */
class FileReference extends \TYPO3\CMS\Core\Resource\FileReference
{
    protected ?string $mediaKey = null;

    /**
     * @var FileReference[]
     */
    protected array $variants = [];

    private ResourceFactory $factory;

    /**
     * FileReference constructor.
     *
     * @param array<int|string, mixed> $fileReferenceData
     * @throws FileDoesNotExistException
     */
    public function __construct(array $fileReferenceData, ?ResourceFactory $factory = null)
    {
        parent::__construct($fileReferenceData, $factory);
        $this->factory = GeneralUtility::makeInstance(ResourceFactory::class);
    }

    /**
     * @return FileReference[]
     * @throws DBALException
     * @throws ResourceDoesNotExistException
     * @throws Exception
     */
    public function getVariants(): array
    {
        $properties = $this->getProperties();

        // this doesn't need to run if we actually are a variant already
        if ($properties['tablenames'] === 'sys_file_reference'
            && $properties['fieldname'] === 'picture_variants') {
            return $this->variants;
        }

        $db = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');

        $result = $db->select('*')
            ->from('sys_file_reference')
            ->where(
                $db->expr()->and(
                    $db->expr()->eq('uid_foreign', $properties['uid']),
                    $db->expr()->eq('tablenames', $db->quote('sys_file_reference')),
                    $db->expr()->eq('fieldname', $db->quote('picture_variants')),
                )
            )
            ->orderBy('sorting_foreign')
            ->executeQuery();

        $collectedMediaQuery = [];
        while ($row = $result->fetchAssociative()) {
            $variant = $this->factory->getFileReferenceObject($row['uid'], $row);
            $collectedMediaQuery[] = $variant->getProperties()['media_width'];
            $this->variants[$variant->getProperties()['media_width']] = $variant;
        }
        $possibleVariants = $this->loadPossibleVariants(
            $this->propertiesOfFileReference['tablenames'],
            $this->propertiesOfFileReference['fieldname'],
            $this->propertiesOfFileReference['uid_foreign']
        );
        if (
            isset($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['autocreatevariations'])
            && (bool)$GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['autocreatevariations']
        ) {
            foreach ($possibleVariants as $key => $config) {
                if (!in_array($key, $collectedMediaQuery)) {
                    $variant = clone $this;
                    $variant->markAsVariation($key);
                    $this->variants[$key] = $variant;
                }
                $this->variants[$key]->mergedProperties['mediaQuery'] = $config['mediaQuery'] ?? '';
            }
        }

        foreach ($possibleVariants as $key => $config) {
            $this->variants[$key]->mergedProperties['mediaQuery'] = $config['mediaQuery'] ?? '';
        }

        return $this->variants;
    }

    public function isVariant(): bool
    {
        if ($this->mediaKey !== null) {
            return true;
        }

        $properties = $this->getProperties();

        // this doesn't need to run if we actually are a variant already
        return $properties['tablenames'] === 'sys_file_reference'
            && $properties['fieldname'] === 'picture_variants';
    }

    private function markAsVariation(string $mediaKey): void
    {
        $this->getProperties();
        $this->mediaKey = $mediaKey;
        $this->mergedProperties['cropVariant'] = $mediaKey;
        $this->mergedProperties['tablenames'] = 'sys_file_reference';
        $this->mergedProperties['fieldname'] = 'picture_variants';
    }

    /**
     * @return string
     */
    public function getMediaquery(): string
    {
        if (!$this->isVariant()) {
            return '';
        }
        if ($this->mediaKey !== null) {
            foreach ($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['sizes.'] as $key => $config) {
                if ($config['key'] === $this->mediaKey) {
                    return $config['mediaquery'];
                }
            }
        }
        $properties = $this->getProperties();
        if (isset($properties['media_width']) && isset($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['sizes.'])) {
            foreach ($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['sizes.'] as $key => $config) {
                $keyWithoutTrailingDot = preg_replace('/\.$/', '', $key);
                if ($keyWithoutTrailingDot === $properties['media_width']) {
                    return $config['mediaquery'];
                }
            }
        }
        return '';
    }

    /**
     * @return string
     */
    public function getVariationmaxwidth(): string
    {
        if ($this->isVariant()) {
            if ($this->mediaKey !== null) {
                foreach ($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['sizes.'] as $key => $config) {
                    if ($config['key'] === $this->mediaKey) {
                        return $config['maxW'];
                    }
                }
            }
            $properties = $this->getProperties();
            if (isset($properties['media_width']) && isset($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['sizes.'])) {
                foreach ($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['sizes.'] as $key => $config) {
                    if ($key === $properties['media_width']) {
                        return $config['maxW'];
                    }
                }
            }
        }
        return '';
    }

    /**
     * @return string
     */
    public function getVariationmaxheight(): string
    {
        $height = '';
        if ($this->isVariant()) {
            if ($this->mediaKey !== null) {
                foreach ($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['sizes.'] as $key => $config) {
                    if ($key === $this->mediaKey) {
                        return $config['maxH'];
                    }
                }
            }
            $properties = $this->getProperties();
            if (isset($properties['media_width']) && isset($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['sizes.'])) {
                foreach ($GLOBALS['TSFE']->config['config']['tx_responsivepicture.']['sizes.'] as $key => $config) {
                    if ($key === $properties['media_width']) {
                        $height = $config['maxH'];
                    }
                }
            }
        }
        return '';
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    private function loadPossibleVariants(string $table, string $field, int $uid): array
    {
        $possibleVariants = $GLOBALS['TCA']['sys_file_reference']['columns']['crop']['config']['cropVariants'] ??= [];

        // table has no Types defined, early exit
        if (!isset($GLOBALS['TCA'][$table]['ctrl']['type'])) {
            return $possibleVariants;
        }

        $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'];

        $db = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table);
        $result = $db
            ->select(
                [$typeField],
                $table,
                [
                    'uid' => $uid,
                ]
            );
        if (!($row = $result->fetchAssociative())) {
            return $possibleVariants;
        }
        $type = $row[$typeField];

        if (!isset($GLOBALS['TCA'][$table]['types'][$type]['columnsOverrides'][$field]['config']['cropVariants'])) {
            return $possibleVariants;
        }

        return $GLOBALS['TCA'][$table]['types'][$type]['columnsOverrides'][$field]['config']['cropVariants'];
    }
}
