<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Ecentral\CantoSaasFal\Domain\Model\Dto;

use Ecentral\CantoSaasApiClient\Http\Asset\SearchRequest;

class AssetSearch
{
    protected string $keyword = '';

    protected int $start = 0;

    protected int $limit = 30;

    protected array $schemes = [
        SearchRequest::SCHEME_IMAGE,
        SearchRequest::SCHEME_DOCUMENT
    ];

    public function getKeyword(): string
    {
        return $this->keyword;
    }

    public function setKeyword(string $keyword): AssetSearch
    {
        $this->keyword = $keyword;
        return $this;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function setStart(int $start): AssetSearch
    {
        $this->start = $start;
        return $this;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setLimit(int $limit): AssetSearch
    {
        $this->limit = $limit;
        return $this;
    }

    public function getStatus(): string
    {
        return SearchRequest::APPROVAL_APPROVED;
    }

    public function setSchemes(array $schemes): AssetSearch
    {
        $this->schemes = $schemes;
        return $this;
    }

    public function getSchemes(): array
    {
        return $this->schemes;
    }
}
