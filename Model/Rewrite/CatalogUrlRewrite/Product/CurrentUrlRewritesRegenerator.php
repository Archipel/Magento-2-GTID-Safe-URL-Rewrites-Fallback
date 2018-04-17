<?php
/**
 * Copyright © 2017 Chad A. Carino. All rights reserved.
 * See LICENSE file for license details.
 *
 * @package    Bangerkuwranger/GtidSafeUrlRewriteFallback
 * @author     Chad A. Carino <artist@chadacarino.com>
 * @author     Burak Bingollu <burak.bingollu@gmail.com>
 * @copyright  2017 Chad A. Carino
 * @license    https://opensource.org/licenses/MIT  MIT License
 */
namespace Bangerkuwranger\GtidSafeUrlRewriteFallback\Model\Rewrite\CatalogUrlRewrite\Product;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\OptionProvider;
use Magento\CatalogUrlRewrite\Model\ObjectRegistry;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;
use Bangerkuwranger\GtidSafeUrlRewriteFallback\Model\Rewrite\CatalogUrlRewrite\Map\UrlRewriteFinder;
use Magento\Framework\App\ObjectManager;
use Magento\UrlRewrite\Model\MergeDataProviderFactory;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CurrentUrlRewritesRegenerator extends \Magento\CatalogUrlRewrite\Model\Product\CurrentUrlRewritesRegenerator
{
    /**
     * @var Product
     * @deprecated
     */
    protected $product;

    /**
     * @var ObjectRegistry
     * @deprecated
     */
    protected $productCategories;

    /**
     * @var UrlFinderInterface
     * @deprecated
     */
    protected $urlFinder;

    /** @var ProductUrlPathGenerator */
    protected $productUrlPathGenerator;

    /** @var UrlRewriteFactory */
    protected $urlRewriteFactory;

    /**
     * Data class for url storage.
     *
     * @var UrlRewrite
     */
    private $urlRewritePrototype;

    /**
     * Finds specific queried url rewrites identified by specific fields.
     * @var UrlRewriteFinder
     */
    private $urlRewriteFinder;

    /**
     * Container for new generated url rewrites.
     *
     * @var \Magento\UrlRewrite\Model\MergeDataProvider
     */
    private $mergeDataProviderPrototype;

    /**
     * @param UrlFinderInterface $urlFinder
     * @param ProductUrlPathGenerator $productUrlPathGenerator
     * @param UrlRewriteFactory $urlRewriteFactory
     * @param UrlRewriteFinder|null $urlRewriteFinder
     * @param \Magento\UrlRewrite\Model\MergeDataProviderFactory|null $mergeDataProviderFactory
     */
    public function __construct(
        UrlFinderInterface $urlFinder,
        ProductUrlPathGenerator $productUrlPathGenerator,
        UrlRewriteFactory $urlRewriteFactory,
        UrlRewriteFinder $urlRewriteFinder = null,
        MergeDataProviderFactory $mergeDataProviderFactory = null
    ) {
        $this->urlFinder = $urlFinder;
        $this->productUrlPathGenerator = $productUrlPathGenerator;
        $this->urlRewriteFactory = $urlRewriteFactory;
        $this->urlRewritePrototype = $urlRewriteFactory->create();
        $this->urlRewriteFinder = $urlRewriteFinder ?: ObjectManager::getInstance()->get(UrlRewriteFinder::class);
        if (!isset($mergeDataProviderFactory)) {
            $mergeDataProviderFactory = ObjectManager::getInstance()->get(MergeDataProviderFactory::class);
        }
        $this->mergeDataProviderPrototype = $mergeDataProviderFactory->create();
    }

    /**
     * Generate list based on current rewrites
     *
     * @param int $storeId
     * @param Product $product
     * @param ObjectRegistry $productCategories
     * @param int|null $rootCategoryId
     * @return UrlRewrite[]
     */
    public function generate($storeId, Product $product, ObjectRegistry $productCategories, $rootCategoryId = null)
    {
        $mergeDataProvider = clone $this->mergeDataProviderPrototype;
        $currentUrlRewrites = $this->urlRewriteFinder->findAllByData(
            $product->getEntityId(),
            $storeId,
            ProductUrlRewriteGenerator::ENTITY_TYPE,
            $rootCategoryId
        );

        foreach ($currentUrlRewrites as $currentUrlRewrite) {
            $category = $this->retrieveCategoryFromMetadata($currentUrlRewrite, $productCategories);
            if ($category === false) {
                continue;
            }
            $generated = $currentUrlRewrite->getIsAutogenerated()
                ? $this->generateForAutogenerated($currentUrlRewrite, $storeId, $category, $product)
                : $this->generateForCustom($currentUrlRewrite, $storeId, $category, $product);

            $mergeDataProvider->merge($generated);
        }

        return $mergeDataProvider->getData();
    }

    /**
     * @param UrlRewrite $url
     * @param int $storeId
     * @param Category|null $category
     * @param Product|null $product
     * @return array
     */
    protected function generateForAutogenerated($url, $storeId, $category, $product = null)
    {
        if ($product->getData('save_rewrites_history')) {
            $targetPath = $this->productUrlPathGenerator->getUrlPathWithSuffix($product, $storeId, $category);
            if ($url->getRequestPath() !== $targetPath) {
                $generatedUrl = clone $this->urlRewritePrototype;
                $generatedUrl->setEntityType(ProductUrlRewriteGenerator::ENTITY_TYPE)
                    ->setEntityId($product->getEntityId())
                    ->setRequestPath($url->getRequestPath())
                    ->setTargetPath($targetPath)
                    ->setRedirectType(OptionProvider::PERMANENT)
                    ->setStoreId($storeId)
                    ->setDescription($url->getDescription())
                    ->setIsAutogenerated(0)
                    ->setMetadata($url->getMetadata());

                return [$generatedUrl];
            }
        }

        return [];
    }

    /**
     * @param UrlRewrite $url
     * @param int $storeId
     * @param Category|null $category
     * @param Product|null $product
     * @return UrlRewrite[]
     */
    protected function generateForCustom($url, $storeId, $category, $product = null)
    {
        $targetPath = $url->getRedirectType()
            ? $this->productUrlPathGenerator->getUrlPathWithSuffix($product, $storeId, $category)
            : $url->getTargetPath();
        if ($url->getRequestPath() !== $targetPath) {
            $generatedUrl = clone $this->urlRewritePrototype;
            $generatedUrl->setEntityType(ProductUrlRewriteGenerator::ENTITY_TYPE)
                ->setEntityId($product->getEntityId())
                ->setRequestPath($url->getRequestPath())
                ->setTargetPath($targetPath)
                ->setRedirectType($url->getRedirectType())
                ->setStoreId($storeId)
                ->setDescription($url->getDescription())
                ->setIsAutogenerated(0)
                ->setMetadata($url->getMetadata());

            return [$generatedUrl];
        }

        return [];
    }

    /**
     * @param UrlRewrite $url
     * @param ObjectRegistry|null $productCategories
     * @return Category|null|bool
     */
    protected function retrieveCategoryFromMetadata($url, ObjectRegistry $productCategories = null)
    {
        $metadata = $url->getMetadata();
        if (isset($metadata['category_id'])) {
            $category = $productCategories->get($metadata['category_id']);
            return $category === null ? false : $category;
        }

        return null;
    }
}
