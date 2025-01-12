<?php
namespace FluidTYPO3\Flux\Controller;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Provider\Interfaces\BasicProviderInterface;
use FluidTYPO3\Flux\Service\FluxService;
use FluidTYPO3\Flux\Service\PageService;
use TYPO3\CMS\Extbase\Mvc\Response;

/**
 * Page Controller
 *
 * @route off
 */
class PageController extends AbstractFluxController implements PageControllerInterface
{

    /**
     * @var string
     */
    protected $fluxRecordField = 'tx_fed_page_flexform';

    /**
     * @var string
     */
    protected $fluxTableName = 'pages';

    /**
     * @var PageService
     */
    protected $pageService;

    /**
     * @var FluxService
     */
    protected $pageConfigurationService;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @param PageService $pageService
     * @return void
     */
    public function injectPageService(PageService $pageService)
    {
        $this->pageService = $pageService;
    }

    /**
     * @param FluxService $pageConfigurationService
     * @return void
     */
    public function injectPageConfigurationService(FluxService $pageConfigurationService)
    {
        $this->pageConfigurationService = $pageConfigurationService;
    }

    /**
     * @throws \RuntimeException
     * @return void
     */
    protected function initializeProvider()
    {
        $record = $this->getRecord();
        if ($record !== null) {
            $provider = $this->pageConfigurationService->resolvePageProvider($record);
            if ($provider instanceof BasicProviderInterface) {
                $this->provider = $provider;
            }
        }
    }

    /**
     * @return array|null
     */
    public function getRecord()
    {
        return $GLOBALS['TSFE']->page ?? null;
    }
}
