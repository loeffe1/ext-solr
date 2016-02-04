<?php
namespace ApacheSolrForTypo3\Solr\Facet;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2010-2011 Markus Goldbach <markus.goldbach@dkd.de>
 *  (c) 2012-2015 Ingo Renner <ingo@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ApacheSolrForTypo3\Solr\Query;
use ApacheSolrForTypo3\Solr\Template;
use ApacheSolrForTypo3\Solr\Util;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Default facet renderer.
 *
 * @author Markus Goldbach <markus.goldbach@dkd.de>
 * @author Ingo Renner <ingo@typo3.org>
 */
class SimpleFacetOptionsRenderer implements FacetOptionsRenderer
{

    /**
     * The facet's name as configured in TypoScript.
     *
     * @var string
     */
    protected $facetName;

    /**
     * The facet's TypoScript configuration.
     *
     * @var string
     */
    protected $facetConfiguration;

    /**
     * The facet options the user can select from.
     *
     * @var array
     */
    protected $facetOptions = array();

    /**
     * Template engine to replace template markers with their values.
     *
     * @var Template
     */
    protected $template;

    /**
     * The query which is going to be sent to Solr when a user selects a facet.
     *
     * @var Query
     */
    protected $query;

    /**
     * Link target page id.
     *
     * @var integer
     */
    protected $linkTargetPageId = 0;


    /**
     * Constructor
     *
     * @param string $facetName The facet's name
     * @param array $facetOptions The facet's options.
     * @param Template $template Template to use to render the facet
     * @param Query $query Query instance used to build links.
     */
    public function __construct(
        $facetName,
        array $facetOptions,
        Template $template,
        Query $query
    ) {
        $this->facetName = $facetName;
        $this->facetOptions = $facetOptions;

        $this->template = clone $template;
        $this->query = $query;

        $solrConfiguration = Util::getSolrConfiguration();
        $this->facetConfiguration = $solrConfiguration->getSearchFacetingFacetByName($facetName);
    }

    /**
     * Sets the link target page Id for links generated by the query linking
     * methods.
     *
     * @param integer $linkTargetPageId The link target page Id.
     */
    public function setLinkTargetPageId($linkTargetPageId)
    {
        $this->linkTargetPageId = intval($linkTargetPageId);
    }

    /**
     * Renders the complete facet.
     *
     * @return string Rendered HTML representing the facet.
     */
    public function renderFacetOptions()
    {
        $facetOptionLinks = array();
        $solrConfiguration = Util::getSolrConfiguration();
        $this->template->workOnSubpart('single_facet_option');

        if (!empty($this->facetConfiguration['manualSortOrder'])) {
            $this->sortFacetOptionsByUserDefinedOrder();
        }

        if (!empty($this->facetConfiguration['reverseOrder'])) {
            $this->facetOptions = array_reverse($this->facetOptions, true);
        }

        $i = 0;
        foreach ($this->facetOptions as $facetOption => $facetOptionResultCount) {
            $facetOption = (string)$facetOption;
            if ($facetOption == '_empty_') {
                // TODO - for now we don't handle facet missing.
                continue;
            }

            $facetOption = GeneralUtility::makeInstance('ApacheSolrForTypo3\\Solr\\Facet\\FacetOption',
                $this->facetName,
                $facetOption,
                $facetOptionResultCount
            );
            /* @var $facetOption FacetOption */

            $facetLinkBuilder = GeneralUtility::makeInstance('ApacheSolrForTypo3\\Solr\\Facet\\LinkBuilder',
                $this->query,
                $this->facetName,
                $facetOption
            );
            /* @var $facetLinkBuilder LinkBuilder */
            $facetLinkBuilder->setLinkTargetPageId($this->linkTargetPageId);

            $optionText = $facetOption->render();
            $optionLink = $facetLinkBuilder->getAddFacetOptionLink($optionText);
            $optionLinkUrl = $facetLinkBuilder->getAddFacetOptionUrl();

            $optionHidden = '';
            if (++$i > $solrConfiguration->getSearchFacetingLimit()) {
                $optionHidden = 'tx-solr-facet-hidden';
            }

            $optionSelected = $facetOption->isSelectedInFacet($this->facetName);

            // negating the facet option links to remove a filter
            if ($this->facetConfiguration['selectingSelectedFacetOptionRemovesFilter']
                && $optionSelected
            ) {
                $optionLink = $facetLinkBuilder->getRemoveFacetOptionLink($optionText);
                $optionLinkUrl = $facetLinkBuilder->getRemoveFacetOptionUrl();
            } elseif ($this->facetConfiguration['singleOptionMode']) {
                $optionLink = $facetLinkBuilder->getReplaceFacetOptionLink($optionText);
                $optionLinkUrl = $facetLinkBuilder->getReplaceFacetOptionUrl();
            }

            $facetOptionLinks[] = array(
                'hidden' => $optionHidden,
                'link' => $optionLink,
                'url' => $optionLinkUrl,
                'text' => $optionText,
                'value' => $facetOption->getValue(),
                'count' => $facetOption->getNumberOfResults(),
                'selected' => $optionSelected ? '1' : '0',
                'facet_name' => $this->facetName
            );
        }

        $this->template->addLoop('facet_links', 'facet_link',
            $facetOptionLinks);

        return $this->template->render();
    }

    /**
     * Sorts the facet options as defined in the facet's manualSortOrder
     * configuration option.
     *
     * @return void
     */
    protected function sortFacetOptionsByUserDefinedOrder()
    {
        $sortedOptions = array();

        $manualFacetOptionSortOrder = GeneralUtility::trimExplode(',',
            $this->facetConfiguration['manualSortOrder']);
        $availableFacetOptions = array_keys($this->facetOptions);

        // move the configured options to the top, in their defined order
        foreach ($manualFacetOptionSortOrder as $manuallySortedFacetOption) {
            if (in_array($manuallySortedFacetOption, $availableFacetOptions)) {
                $sortedOptions[$manuallySortedFacetOption] = $this->facetOptions[$manuallySortedFacetOption];
                unset($this->facetOptions[$manuallySortedFacetOption]);
            }
        }

        // set the facet options to the new order,
        // appending the remaining unsorted/regularly sorted options
        $this->facetOptions = $sortedOptions + $this->facetOptions;
    }
}
