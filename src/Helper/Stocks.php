<?php
/**
 * ScandiPWA_CatalogGraphQl
 *
 * @category    ScandiPWA
 * @package     ScandiPWA_CatalogGraphQl
 * @author      Alfreds Genkins <info@scandiweb.com>
 * @copyright   Copyright (c) 2019 Scandiweb, Ltd (https://scandiweb.com)
 */

declare(strict_types=1);

namespace ScandiPWA\CatalogGraphQl\Helper;

use Magento\CatalogInventory\Model\Configuration;
use Magento\Framework\App\Helper\Context;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Status\StockStatusCriteria;
use Magento\CatalogInventory\Api\StockStatusRepositoryInterface;

class Stocks extends AbstractHelper {
    const STOCK_STATUS = 'stock_status';
    const MIN_STOCK_QTY = 'min_sale_qty';
    const MAX_STOCK_QTY = 'max_sale_qty';
    const ONLY_X_LEFT_IN_STOCK = 'only_x_left_in_stock';

    /**
     * @var StockStatusRepositoryInterface
     */
    private $stockStatusRepository;

    /**
     * @var SourceItemRepositoryInterface
     */
    protected $stockRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var StockStatusCriteria
     */
    protected $stockStatusCriteria;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Stocks constructor.
     * @param Context $context
     * @param SourceItemRepositoryInterface $stockRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ScopeConfigInterface $scopeConfig
     * @param StockStatusCriteria $stockStatusCriteria
     * @param StockStatusRepositoryInterface $stockStatusRepository
     */
    public function __construct(
        Context $context,
        SourceItemRepositoryInterface $stockRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ScopeConfigInterface $scopeConfig,
        StockStatusCriteria $stockStatusCriteria,
        StockStatusRepositoryInterface $stockStatusRepository
    ) {
        parent::__construct($context);

        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->stockRepository = $stockRepository;
        $this->scopeConfig = $scopeConfig;
        $this->stockStatusCriteria = $stockStatusCriteria;
        $this->stockStatusRepository = $stockStatusRepository;
    }

    /**
     * @param $node
     * @return string[]
     */
    protected function getFieldContent($node)
    {
        $stocks = [];
        $validFields = [
            self::STOCK_STATUS,
            self::MIN_STOCK_QTY,
            self::MAX_STOCK_QTY,
            self::ONLY_X_LEFT_IN_STOCK
        ];

        foreach ($node->selectionSet->selections as $selection) {
            if (!isset($selection->name)) {
                continue;
            };

            $name = $selection->name->value;

            if (in_array($name, $validFields)) {
                $stocks[] = $name;
            }
        }

        return $stocks;
    }

    public function getProductStocks($products, $info)
    {
        $fields = $this->getFieldsFromProductInfo($info);
        $productStocks = [];

        if (!count($fields)) {
            return $productStocks;
        }

        list($productIds, $productSKUs) = $this->getProductIdentificators($products);

        $thresholdQty = 0;

        if (in_array(self::ONLY_X_LEFT_IN_STOCK, $fields)) {
            $thresholdQty = (float)$this->scopeConfig->getValue(
                Configuration::XML_PATH_STOCK_THRESHOLD_QTY,
                ScopeInterface::SCOPE_STORE
            );
        }

        // inventory_source_item
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(SourceItemInterface::SKU, $productSKUs, 'in')
            ->create();

        $stockItems = $this->stockRepository->getList($criteria)->getItems();

        if (!count($stockItems)) {
            return $productStocks;
        }

        $formattedStocks = [];

        foreach ($stockItems as $stockItem) {
            $inStock = $stockItem->getStatus() === SourceItemInterface::STATUS_IN_STOCK;

            $leftInStock = null;

            if ($thresholdQty !== (float) 0) {
                $qty = $stockItem->getQuantity();
                $isThresholdPassed = $qty <= $thresholdQty;
                $leftInStock = $isThresholdPassed ? $qty : null;
            }

            $formattedStocks[$stockItem->getSku()] = [
                'stock_status' => $inStock ? 'IN_STOCK' : 'OUT_OF_STOCK',
                'only_x_left_in_stock' => $leftInStock
            ];
        }

        $stocksMinMax = $this->getProductMinMaxSalableQties($productIds);

        foreach ($products as $product) {
            $id = $product->getId();
            $sku = $product->getSku();

            if (isset($formattedStocks[$sku])) {
                $productStocks[$id] = array_merge($formattedStocks[$sku], $stocksMinMax[$id]);
            }
        }

        return $productStocks;
    }

    private function getProductIdentificators($products): array
    {
        $productIds = [];
        $productSKUs = [];

        foreach ($products as $product) {
            $productIds[] = $product->getId();
            $productSKUs[] = $product->getSku();
        }

        return [$productIds, $productSKUs];
    }

    private function getProductMinMaxSalableQties(array $productIds): array
    {
        $criteria = $this->stockStatusCriteria;
        $criteria->setProductsFilter($productIds);

        $minMaxSalableQties = [];

        $items = $this->stockStatusRepository->getList($criteria)->getItems();
        foreach ($items as $productId => $stockStatus) {
            $stockItem = $stockStatus->getStockItem();

            $minMaxSalableQties[$productId] = [
                'min_sale_qty' => $stockItem->getMinSaleQty(),
                'max_sale_qty' => $stockItem->getMaxSaleQty(),
            ];
        }

        return $minMaxSalableQties;
    }
}