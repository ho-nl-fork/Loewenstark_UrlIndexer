<?php

/**
 * Loewenstark_UrlIndexer
 *
 * @category  Loewenstark
 * @package   Loewenstark_UrlIndexer
 * @author    Mathis Klooss <m.klooss@loewenstark.com>
 * @copyright 2013 Loewenstark Web-Solution GmbH (http://www.mage-profis.de/). All rights served.
 * @license   https://github.com/mklooss/Loewenstark_UrlIndexer/blob/master/README.md
 */
class Loewenstark_UrlIndexer_Model_Url
	extends Mage_Catalog_Model_Url
{
	CONST XML_PATH_DISABLE_CATEGORIE = 'catalog/seo_product/use_categories';

	protected $_urlKey = false;

    /**
     * Get requestPath that was not used yet.
     *
     * Will try to get unique path by adding -1 -2 etc. between url_key and optional url_suffix
     *
     * @param int $storeId
     * @param string $requestPath
     * @param string $idPath
     * @return string
     */
    public function getUnusedPath($storeId, $requestPath, $idPath)
    {
        if (strpos($idPath, 'product') !== false) {
            $suffix = $this->getProductUrlSuffix($storeId);
        } else {
            $suffix = $this->getCategoryUrlSuffix($storeId);
        }
        if (empty($requestPath)) {
            $requestPath = '-';
        } elseif ($requestPath == $suffix) {
            $requestPath = '-' . $suffix;
        }

        /**
         * Validate maximum length of request path
         */
        if (strlen($requestPath) > self::MAX_REQUEST_PATH_LENGTH + self::ALLOWED_REQUEST_PATH_OVERFLOW) {
            $requestPath = substr($requestPath, 0, self::MAX_REQUEST_PATH_LENGTH);
        }

        if (isset($this->_rewrites[$idPath])) {
            $this->_rewrite = $this->_rewrites[$idPath];
            if ($this->_rewrites[$idPath]->getRequestPath() == $requestPath) {
                return $requestPath;
            }
        }
        else {
            $this->_rewrite = null;
        }

        $rewrite = $this->getResource()->getRewriteByRequestPath($requestPath, $storeId);
        if ($rewrite && $rewrite->getId()) {
            if ($rewrite->getIdPath() == $idPath) {
                $this->_rewrite = $rewrite;
                return $requestPath;
            }
            // match request_url abcdef1234(-12)(.html) pattern
            $match = array();
            $regularExpression = '#^([0-9a-z/-]+?)(-([0-9]+))?('.preg_quote($suffix).')?$#i';
            if (!preg_match($regularExpression, $requestPath, $match)) {
                return $this->getUnusedPath($storeId, '-', $idPath);
            }
            $match[1] = $match[1] . '-';
            $match[4] = isset($match[4]) ? $match[4] : '';

            $lastRequestPath = $this->getResource()
                ->getLastUsedRewriteRequestIncrement($match[1], $match[4], $storeId);
            if ($lastRequestPath) {
                $match[3] = $lastRequestPath;
            }
            return $match[1]
                . (isset($match[3]) ? ($match[3]+1) : '1')
                . $match[4];
        }
        else {
            return $requestPath;
        }
    }

	/**
	 * Get unique product request path
	 *
	 * @param   Varien_Object $product
	 * @param   Varien_Object $category
	 * @return  string
	 */
	public function getProductRequestPath($product, $category)
	{
		$url = parent::getProductRequestPath($product, $category);
		$this->_urlKey = false;
		$suffix = $this->getProductUrlSuffix($category->getStoreId());
		$urlKey = basename($url, $suffix); // get current url key
		if ($this->_helper()->isEnabled() && $category->getLevel() == 1 && ($product->getUrlKey() == '' || $urlKey != $product->getUrlKey())) {
			$this->_urlKey = $urlKey;
			$product->setUrlKey($urlKey);
			$this->getResource()->saveProductAttribute($product, 'url_key');
		}
		return $url;
	}

	/**
	 * refresh url rewrites by product ids
	 *
	 * @param array $productIds
	 * @param null|int $store_id
	 * @return Loewenstark_UrlIndexer_Model_Url
	 */
	public function refreshProductRewriteByIds($productIds, $store_id = null)
	{
		$stores = array();
		if (is_null($store_id)) {
			$stores = $this->getStores();
		} else {
			$stores = array((int)$store_id => $this->getStores($store_id));
		}
		foreach ($stores as $storeId => $store) {
			$storeRootCategoryId = $store->getRootCategoryId();
			$storeRootCategory = $this->getResource()->getCategory($storeRootCategoryId, $storeId);
			$process = true;
			$lastEntityId = 0;
			while ($process == true) {
				$products = $this->getResource()->getProductsByIds($productIds, $storeId, $lastEntityId);
				if (!$products) {
					$process = false;
					break;
				}
				foreach ($products as $product) {
					$categories = $this->getResource()->getCategories($product->getCategoryIds(), $storeId);
					if (!isset($categories[$storeRootCategoryId])) {
						$categories[$storeRootCategoryId] = $storeRootCategory;
					}
					foreach ($categories as $category) {
						$this->_refreshProductRewrite($product, $category);
					}
				}
			}
		}
		return $this;
	}

	/**
	 * Refresh product rewrite
	 *
	 * @param Varien_Object $product
	 * @param Varien_Object $category
	 * @return Mage_Catalog_Model_Url
	 */
	protected function _refreshProductRewrite(Varien_Object $product, Varien_Object $category)
	{
		if ($category->getId() == $category->getPath()) {
			return $this;
		}
		parent::_refreshProductRewrite($product, $category);
		if ($this->_helper()->isEnabled() && $this->_urlKey && $this->_urlKey != $product->getUrlKey()) {
			$product->setUrlKey($this->_urlKey);
			$this->getResource()->saveProductAttribute($product, 'url_key');
		}
		return $this;
	}

	/**
	 * Refresh products for category
	 *
	 * @param Varien_Object $category
	 * @return Mage_Catalog_Model_Url
	 */
	protected function _refreshCategoryProductRewrites(Varien_Object $category)
	{
		if (! $this->_helper()->DoNotUseCategoryPathInProduct($category->getStoreId())) {
			return parent::_refreshCategoryProductRewrites($category);
		}
		return $this;
	}

	/**
	 * Retrieve resource model, just for phpDoc :)
	 *
	 * @return Loewenstark_UrlIndexer_Model_Resource_Url
	 */
	public function getResource()
	{
		return parent::getResource();
	}

	/**
	 *
	 * @return Loewenstark_UrlIndexer_Helper_Data
	 */
	protected function _helper()
	{
		return Mage::helper('urlindexer');
	}
}
