<?php

/**
 * Catalog url model
 *
 * @category   DR
 * @package    DR_DR_FastCatalogUrlIndexer
 * @author     Daniel Rose <daniel-rose@gmx.de>
 * @copyright  Copyright (c) 2015 Daniel Rose
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class DR_FastCatalogUrlIndexer_Model_Catalog_Url extends Mage_Catalog_Model_Url
{
    const XML_PATH_EXCLUDE_DISABLED_PRODUCTS = 'catalog/url_rewrite_indexer/exclude_disbaled_products';
    const XML_PATH_EXCLUDE_NOT_VISIBLE_PRODUCTS = 'catalog/url_rewrite_indexer/exclude_not_visible_products';

    /**
     * Get unique product request path
     *
     * @param   Varien_Object $product
     * @param   Varien_Object $category
     * @return  string
     */
    public function getProductRequestPath($product, $category)
    {
        if ($product->getUrlKey() == '') {
            $urlKey = $this->getProductModel()->formatUrlKey($product->getName());
        } else {
            $urlKey = $this->getProductModel()->formatUrlKey($product->getUrlKey());
        }
        $storeId = $category->getStoreId();
        $suffix  = $this->getProductUrlSuffix($storeId);
        $idPath  = $this->generatePath('id', $product, $category);
        /**
         * Prepare product base request path
         */
        if ($category->getLevel() > 1) {
            // To ensure, that category has path either from attribute or generated now
            $this->_addCategoryUrlPath($category);
            $categoryUrl = Mage::helper('catalog/category')->getCategoryUrlPath($category->getUrlPath(),
                false, $storeId);
            $requestPath = $categoryUrl . '/' . $urlKey;
        } else {
            $requestPath = $urlKey;
        }

        if (strlen($requestPath) > self::MAX_REQUEST_PATH_LENGTH + self::ALLOWED_REQUEST_PATH_OVERFLOW) {
            $requestPath = substr($requestPath, 0, self::MAX_REQUEST_PATH_LENGTH);
        }

        $this->_rewrite = null;
        /**
         * Check $requestPath should be unique
         */
        if (isset($this->_rewrites[$idPath])) {
            $this->_rewrite = $this->_rewrites[$idPath];
            $existingRequestPath = $this->_rewrites[$idPath]->getRequestPath();

            if ($existingRequestPath == $requestPath . $suffix) {
                return $existingRequestPath;
            }

            $existingRequestPath = preg_replace('/' . preg_quote($suffix, '/') . '$/', '', $existingRequestPath);
            /**
             * Check if existing request past can be used
             */
            if (!empty($requestPath)
                && strpos($existingRequestPath, $requestPath) === 0
            ) {
                $existingRequestPath = preg_replace(
                    '/^' . preg_quote($requestPath, '/') . '/', '', $existingRequestPath
                );
                if (preg_match('#^-([0-9]+)$#i', $existingRequestPath)) {
                    return $this->_rewrites[$idPath]->getRequestPath();
                }
            }

            $fullPath = $requestPath.$suffix;
            if ($this->_deleteOldTargetPath($fullPath, $idPath, $storeId)) {
                return $fullPath;
            }
        }
        /**
         * Check 2 variants: $requestPath and $requestPath . '-' . $productId
         */
        $validatedPath = $this->getResource()->checkRequestPaths(
            array($requestPath.$suffix, $requestPath.'-'.$product->getId().$suffix),
            $storeId
        );

        if ($validatedPath) {
            return $validatedPath;
        }
        /**
         * Use unique path generator
         */
        return $this->getUnusedPath($storeId, $requestPath.$suffix, $idPath);
    }

    /**
     * Retrieve resource model
     *
     * @return DR_FastCatalogUrlIndexer_Model_Resource_Catalog_Url
     */
    public function getResource()
    {
        if (is_null($this->_resourceModel)) {
            $this->_resourceModel = Mage::getResourceModel('dr_fastcatalogurlindexer/catalog_url');
        }

        return $this->_resourceModel;
    }

    /**
     * Refresh product rewrite urls for one store or all stores
     * Called as a reaction on product change that affects rewrites
     *
     * @param int $productId
     * @param int|null $storeId
     * @return Mage_Catalog_Model_Url
     */
    public function refreshProductRewrite($productId, $storeId = null)
    {
        if (is_null($storeId)) {
            foreach ($this->getStores() as $store) {
                $this->refreshProductRewrite($productId, $store->getId());
            }
            return $this;
        }

        $product = $this->getResource()->getProduct($productId, $storeId);
        if ($product && $this->_canRefreshProductRewrite($product, $storeId)) {
            $store = $this->getStores($storeId);
            $storeRootCategoryId = $store->getRootCategoryId();

            // List of categories the product is assigned to, filtered by being within the store's categories root
            $categories = $this->getResource()->getCategories($product->getCategoryIds(), $storeId);
            $this->_rewrites = $this->getResource()->prepareRewrites($storeId, '', $productId);

            // Add rewrites for all needed categories
            // If product is assigned to any of store's categories -
            // we also should use store root category to create root product url rewrite
            if (!isset($categories[$storeRootCategoryId])) {
                $categories[$storeRootCategoryId] = $this->getResource()->getCategory($storeRootCategoryId, $storeId);
            }

            // Create product url rewrites
            foreach ($categories as $category) {
                $this->_refreshProductRewrite($product, $category);
            }

            // Remove all other product rewrites created earlier for this store - they're invalid now
            $excludeCategoryIds = array_keys($categories);
            $this->getResource()->clearProductRewrites($productId, $storeId, $excludeCategoryIds);

            unset($categories);
            unset($product);
        } else {
            // Product doesn't belong to this store - clear all its url rewrites including root one
            $this->getResource()->clearProductRewrites($productId, $storeId, array());
        }

        return $this;
    }

    /**
     * Refresh all product rewrites for designated store
     *
     * @param int $storeId
     * @return DR_FastCatalogUrlIndexer_Model_Catalog_Url
     */
    public function refreshProductRewrites($storeId)
    {
        $this->_categories = array();
        $storeRootCategoryId = $this->getStores($storeId)->getRootCategoryId();
        $storeRootCategoryPath = $this->getStores($storeId)->getRootCategoryPath();
        $this->_categories[$storeRootCategoryId] = $this->getResource()->getCategory($storeRootCategoryId, $storeId);

        $lastEntityId = 0;
        $process = true;

        while ($process == true) {
            $products = $this->getResource()->getProductsByStore($storeId, $lastEntityId);
            if (!$products) {
                $process = false;
                break;
            }

            $this->_rewrites = $this->getResource()->prepareRewrites($storeId, false, array_keys($products));

            $loadCategories = array();
            foreach ($products as $product) {
                foreach ($product->getCategoryIds() as $categoryId) {
                    if (!isset($this->_categories[$categoryId])) {
                        $loadCategories[$categoryId] = $categoryId;
                    }
                }
            }

            if ($loadCategories) {
                foreach ($this->getResource()->getCategories($loadCategories, $storeId) as $category) {
                    $this->_categories[$category->getId()] = $category;
                }
            }

            foreach ($products as $product) {
                if (!$this->_canRefreshProductRewrite($product, $storeId)) {
                    continue;
                }

                $this->_refreshProductRewrite($product, $this->_categories[$storeRootCategoryId]);
                foreach ($product->getCategoryIds() as $categoryId) {
                    if ($categoryId != $storeRootCategoryId && isset($this->_categories[$categoryId])) {
                        if (strpos($this->_categories[$categoryId]['path'], $storeRootCategoryPath . '/') !== 0) {
                            continue;
                        }
                        $this->_refreshProductRewrite($product, $this->_categories[$categoryId]);
                    }
                }
            }

            unset($products);
            $this->_rewrites = array();
        }

        $this->_categories = array();
        return $this;
    }

    /**
     * Can refresh product rewrite
     *
     * @param $product
     * @param $storeId
     * @return bool
     */
    protected function _canRefreshProductRewrite($product, $storeId)
    {
        return !(($product->getStatus() == Mage_Catalog_Model_Product_Status::STATUS_DISABLED && Mage::getStoreConfigFlag(self::XML_PATH_EXCLUDE_DISABLED_PRODUCTS, $storeId))
            || ($product->getVisibility() == Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE && Mage::getStoreConfigFlag(self::XML_PATH_EXCLUDE_NOT_VISIBLE_PRODUCTS, $storeId)));
    }
}