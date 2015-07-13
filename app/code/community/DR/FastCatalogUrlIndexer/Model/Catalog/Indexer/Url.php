<?php
/**
 * Catalog url rewrites index model
 *
 * @category   DR
 * @package    DR_DR_FastCatalogUrlIndexer
 * @author     Daniel Rose <daniel-rose@gmx.de>
 * @copyright  Copyright (c) 2015 Daniel Rose
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class DR_FastCatalogUrlIndexer_Model_Catalog_Indexer_Url extends Mage_Catalog_Model_Indexer_Url
{
    /**
     * Process event
     *
     * @param Mage_Index_Model_Event $event
     */
    protected function _processEvent(Mage_Index_Model_Event $event)
    {
        $data = $event->getNewData();
        if (!empty($data['catalog_url_reindex_all'])) {
            $this->reindexAll();
        }

        /* @var $urlModel DR_FastCatalogUrlIndexer_Model_Catalog_Url */
        $urlModel = Mage::getSingleton('dr_fastcatalogurlindexer/catalog_url');

        // Force rewrites history saving
        $dataObject = $event->getDataObject();
        if ($dataObject instanceof Varien_Object && $dataObject->hasData('save_rewrites_history')) {
            $urlModel->setShouldSaveRewritesHistory($dataObject->getData('save_rewrites_history'));
        }

        if(isset($data['rewrite_product_ids'])) {
            $urlModel->clearStoreInvalidRewrites(); // Maybe some products were moved or removed from website
            foreach ($data['rewrite_product_ids'] as $productId) {
                $urlModel->refreshProductRewrite($productId);
            }
        }
        if (isset($data['rewrite_category_ids'])) {
            $urlModel->clearStoreInvalidRewrites(); // Maybe some categories were moved
            foreach ($data['rewrite_category_ids'] as $categoryId) {
                $urlModel->refreshCategoryRewrite($categoryId);
            }
        }
    }

    /**
     * Rebuild all index data
     */
    public function reindexAll()
    {
        /** @var $resourceModel DR_FastCatalogUrlIndexer_Model_Resource_Catalog_Url */
        $resourceModel = Mage::getResourceSingleton('dr_fastcatalogurlindexer/catalog_url');
        $resourceModel->beginTransaction();

        try {
            Mage::getSingleton('dr_fastcatalogurlindexer/catalog_url')->refreshRewrites();
            $resourceModel->commit();
        } catch (Exception $e) {
            $resourceModel->rollBack();
            throw $e;
        }
    }
}