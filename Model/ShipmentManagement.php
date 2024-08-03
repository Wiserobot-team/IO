<?php

/**
 * WISEROBOT INDUSTRIES SDN. BHD. **NOTICE OF LICENSE**
 * This source file is subject to the EULA that is bundled with this package in the file LICENSE.pdf
 * It is also available through the world-wide-web at this URL: http://wiserobot.com/mage_extension_license.pdf
 * =================================================================
 * This package is designed for all versions of Magento
 * =================================================================
 * Copyright (c) 2019 WISEROBOT INDUSTRIES SDN. BHD. (http://www.wiserobot.com)
 * License http://wiserobot.com/mage_extension_license.pdf
 */

declare(strict_types=1);

namespace WiseRobot\Io\Model;

use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Convert\Order as ConvertOrder;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\Sales\Model\Order\Shipment\TrackFactory as ShipmentTrackFactory;
use Magento\Framework\Webapi\Exception as WebapiException;

class ShipmentManagement implements \WiseRobot\Io\Api\ShipmentManagementInterface
{
    /**
     * @var string
     */
    public $logFile = "wr_io_shipment_import.log";
    /**
     * @var array
     */
    public array $results = [];
    /**
     * @var Filesystem
     */
    public $filesystem;
    /**
     * @var ResourceConnection
     */
    public $resourceConnection;
    /**
     * @var StoreManagerInterface
     */
    public $storeManager;
    /**
     * @var OrderFactory
     */
    public $orderFactory;
    /**
     * @var ConvertOrder
     */
    public $convertOrder;
    /**
     * @var ShipmentFactory
     */
    public $shipmentFactory;
    /**
     * @var ShipmentCollectionFactory
     */
    public $shipmentCollectionFactory;
    /**
     * @var ShipmentTrackFactory
     */
    public $shipmentTrackFactory;

    /**
     * @param Filesystem $filesystem
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     * @param OrderFactory $orderFactory
     * @param ConvertOrder $convertOrder
     * @param ShipmentFactory $shipmentFactory
     * @param ShipmentCollectionFactory $shipmentCollectionFactory
     * @param ShipmentTrackFactory $shipmentTrackFactory
     */
    public function __construct(
        Filesystem $filesystem,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager,
        OrderFactory $orderFactory,
        ConvertOrder $convertOrder,
        ShipmentFactory $shipmentFactory,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        ShipmentTrackFactory $shipmentTrackFactory
    ) {
        $this->filesystem = $filesystem;
        $this->resourceConnection = $resourceConnection;
        $this->storeManager = $storeManager;
        $this->orderFactory = $orderFactory;
        $this->convertOrder = $convertOrder;
        $this->shipmentFactory = $shipmentFactory;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->shipmentTrackFactory = $shipmentTrackFactory;
    }

    /**
     * Filter Shipment Data
     *
     * @param int $store
     * @param string $filter
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getList(
        int $store,
        string $filter = "",
        int $page = 1,
        int $limit = 1000
    ): array {
        // create shipment collection
        $shipmentCollection = $this->shipmentCollectionFactory->create();
        $errorMess = "data request error";

        // store info
        if (!$store) {
            $message = "Field: 'store' is a required field";
            $this->results["error"] = $message;
            throw new WebapiException(__($errorMess), 0, 400, $this->results);
        }
        try {
            $storeInfo = $this->storeManager->getStore($store);
        } catch (\Exception $e) {
            $message = "Requested 'store' " . $store . " doesn't exist";
            $this->results["error"] = $message;
            throw new WebapiException(__($errorMess), 0, 400, $this->results);
        }
        $shipmentCollection->addFieldToFilter('store_id', $store);

        // selecting
        $shipmentCollection->addFieldToSelect('*');

        // filtering
        $filter = trim((string) $filter);
        if ($filter) {
            $filterArray = explode(" and ", (string) $filter);
            foreach ($filterArray as $filterItem) {
                $operator = $this->processFilter((string) $filterItem);
                if (!$operator) {
                    continue;
                }
                $condition = array_map('trim', explode($operator, (string) $filterItem));
                if (count($condition) != 2) {
                    continue;
                }
                if (!$condition[0] || !$condition[1]) {
                    continue;
                }
                $fieldName = $condition[0];
                $fieldValue = $condition[1];

                // check if column doesn't exist in shipment table
                $tableName = $this->resourceConnection->getTableName(['sales_shipment', '']);
                if ($this->resourceConnection->getConnection()
                        ->tableColumnExists($tableName, $fieldName) !== true) {
                    $message = "Field: 'filter' - column '" .
                        $fieldName . "' doesn't exist in shipment table";
                    $this->results["error"] = $message;
                    throw new WebapiException(__($errorMess), 0, 400, $this->results);
                }
                $shipmentCollection->addFieldToFilter(
                    $fieldName,
                    [$operator => $fieldValue]
                );
            }
        }
        // sorting
        $shipmentCollection->setOrder('entity_id', 'asc');

        // paging
        $total = $shipmentCollection->getSize();
        if (!$page || $page <= 0) {
            $page = 1;
        }
        if (!$limit || $limit <= 0) {
            $limit = 100;
        }
        if ($limit > 1000) {
            $limit = 1000; // maximum page size
        }

        $result = [];
        $totalPages = ceil($total / $limit);
        if ($page > $totalPages) {
            return $result;
        }

        $shipmentCollection->setPageSize($limit);
        $shipmentCollection->setCurPage($page);
        if ($shipmentCollection->getSize()) {
            foreach ($shipmentCollection as $shipment) {
                $shipmentIId = $shipment->getIncrementId();
                if (!$shipmentIId) {
                    continue;
                }
                // shipment info
                $shipmentData = [];
                $shipmentData['store'] = $storeInfo->getName();
                $shipmentInfo = $this->getShipmentInfo($shipment);
                if (count($shipmentInfo)) {
                    $shipmentData['shipment_info'] = $shipmentInfo;
                }
                $result[$shipmentIId] = $shipmentData;
            }
            return $result;
        }

        return $result;
    }

    /**
     * Process filter data
     *
     * @param string $string
     * @return string
     */
    public function processFilter(string $string): string
    {
        switch ($string) {
            case strpos((string) $string, " eq ") == true:
                $operator = "eq";
                break;
            case strpos((string) $string, " gt ") == true:
                $operator = "gt";
                break;
            case strpos((string) $string, " le ") == true:
                $operator = "le";
                break;
            default:
                $operator = '';
        }

        return $operator;
    }

    /**
     * Get Order Shipment Info
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @return array
     */
    public function getShipmentInfo(
        \Magento\Sales\Model\Order\Shipment $shipment
    ): array {
        $shipmentInfo = [];
        // shipment item
        $itemsData = [];
        $shipmentItems = $shipment->getItemsCollection();
        foreach ($shipmentItems as $shipmentItem) {
            $itemsData[] = [
                "sku" => $shipmentItem->getData("sku"),
                "name" => $shipmentItem->getData("name"),
                "price" => $shipmentItem->getData("price"),
                "qty" => $shipmentItem->getData("qty"),
                "weight" => $shipmentItem->getData("weight")
            ];
        }
        // track info
        $tracksData = [];
        $shipmentTracks = $shipment->getTracksCollection();
        foreach ($shipmentTracks as $shipmentTrack) {
            $tracksData[] = [
                "created_at" => $shipmentTrack->getData("created_at"),
                "updated_at" => $shipmentTrack->getData("updated_at"),
                "carrier_code" => $shipmentTrack->getData("carrier_code"),
                "title" => $shipmentTrack->getData("title"),
                "track_number" => $shipmentTrack->getData("track_number")
            ];
        }

        $shipmentInfo[] = [
            "created_at" => $shipment->getData("created_at"),
            "updated_at" => $shipment->getData("updated_at"),
            "store_id" => $shipment->getData("store_id"),
            "entity_id" => $shipment->getData("entity_id"),
            "increment_id" => $shipment->getData("increment_id"),
            "order_id" => $shipment->getData("order_id"),
            "total_qty" => $shipment->getData("total_qty"),
            "total_weight" => $shipment->getData("total_weight"),
            "item_info" => $itemsData,
            "track_info" => $tracksData
        ];

        return $shipmentInfo;
    }

    /**
     * Import Shipment
     *
     * @param string $orderId
     * @param mixed $shipmentInfo
     * @return array
     */
    public function import(string $orderId, mixed $shipmentInfo): array
    {
        // response messages
        $this->results["response"]["data"]["success"] = [];
        $this->results["response"]["data"]["error"] = [];

        $errorMess = "data request error";

        // order id
        if (!$orderId) {
            $message = "Field: 'order_id' is a required field";
            $this->results["response"]["data"]["error"][] = $message;
            $this->log("ERROR: " . $message);
            $this->cleanResponseMessages();
            throw new WebapiException(__($errorMess), 0, 400, $this->results["response"]);
        }

        // shipment info
        if (!$shipmentInfo || !count($shipmentInfo)) {
            $message = "Field: 'shipment_info' is a required field";
            $this->results["response"]["data"]["error"][] = $message;
            $this->log("ERROR: " . $message);
            $this->cleanResponseMessages();
            throw new WebapiException(__($errorMess), 0, 400, $this->results["response"]);
        }
        foreach ($shipmentInfo as $_shipment) {
            if (!isset($_shipment["shipping_date"]) || !$_shipment["shipping_date"]
                || !isset($_shipment["item_info"]) || !count($_shipment["item_info"])
                || !isset($_shipment["track_info"]) || !count($_shipment["track_info"])) {
                $message = "Field: 'shipment_info' - incorrect data";
                $this->results["response"]["data"]["error"][] = $message;
                $this->log("ERROR: " . $message);
                $this->cleanResponseMessages();
                throw new WebapiException(__($errorMess), 0, 400, $this->results["response"]);
            }
            foreach ($_shipment["item_info"] as $item) {
                if (!isset($item["sku"]) || !$item["sku"] || !isset($item["qty"]) || !$item["qty"]) {
                    $message = "Field: 'item_info' - {'sku','qty'} data fields are required";
                    $this->results["response"]["data"]["error"][] = $message;
                    $this->log("ERROR: " . $message);
                    $this->cleanResponseMessages();
                    throw new WebapiException(__($errorMess), 0, 400, $this->results["response"]);
                }
            }
            foreach ($_shipment["track_info"] as $track) {
                if (!isset($track["carrier_code"]) || !$track["carrier_code"] ||
                    !isset($track["title"]) || !$track["title"]) {
                    $message = "Field: 'track_info' - {'carrier_code','title'} data fields are required";
                    $this->results["response"]["data"]["error"][] = $message;
                    $this->log("ERROR: " . $message);
                    $this->cleanResponseMessages();
                    throw new WebapiException(__($errorMess), 0, 400, $this->results["response"]);
                }
            }
        }

        // import io shipment
        try {
            $this->importIoShipment($orderId, $shipmentInfo);
            $this->cleanResponseMessages();
            return $this->results;
        } catch (\Exception $e) {
            $errorMess = "shipment import error";
            throw new WebapiException(__($errorMess), 0, 400, $this->results["response"]);
        }
    }

    /**
     * Import Io Shipment
     *
     * @param string $orderId
     * @param array $shipmentInfo
     * @return bool
     */
    public function importIoShipment(string $orderId, array $shipmentInfo): bool
    {
        $order = $this->orderFactory->create()
            ->loadByIncrementId($orderId);
        if ($order && $order->getId()) {
            if ($order->getStatus() == "closed") {
                $message = "Skip order " . $orderId . " has been closed";
                $this->results["response"]["data"]["success"][] = $message;
                $this->log($message);
                return false;
            }
            if ($order->hasShipments()) {
                $message = "Skip order " . $orderId . " has been shipped";
                $this->results["response"]["data"]["success"][] = $message;
                $this->log($message);
                return false;
            }
            try {
                // create shipment for order
                $this->createShipment($order, $shipmentInfo);
                return true;
            } catch (\Exception $e) {
                $message = $orderId . ": " . $e->getMessage();
                $this->results["response"]["data"]["error"][] = $message;
                $this->log("ERROR " . $message);
                $this->cleanResponseMessages();
                throw new WebapiException(__($e->getMessage()), 0, 400);
            }
        } else {
            $message = "WARN cannot load order " . $orderId;
            $this->results["response"]["data"]["error"][] = $message;
            $this->log($message);
            return false;
        }
    }

    /**
     * Create Shipment
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $shipmentInfo
     * @return bool
     */
    public function createShipment(
        \Magento\Sales\Model\Order $order,
        array $shipmentInfo
    ): bool {
        $orderId = $order->getIncrementId();
        if ($order->hasShipments()) {
            $message = "Skip order " . $orderId . " for already has shipment";
            $this->results["response"]["data"]["success"][] = $message;
            $this->log($message);
            return false;
        }

        // create shipment
        foreach ($shipmentInfo as $_shipmentInfo) {
            try {
                // shipping date
                if (!$_shipmentInfo["shipping_date"]) {
                    continue;
                }
                // item info
                if (!isset($_shipmentInfo["item_info"]) || !count($_shipmentInfo["item_info"]) ||
                    !isset($_shipmentInfo["item_info"][0])) {
                    continue;
                }
                $hasItem = false;
                foreach ($_shipmentInfo["item_info"] as $itemInfo) {
                    if (!$itemInfo["sku"] || !$itemInfo["qty"]) {
                        continue;
                    }
                    $hasItem = true;
                }
                if (!$hasItem) {
                    continue;
                }
                // track info
                if (!isset($_shipmentInfo["track_info"]) || !count($_shipmentInfo["track_info"]) ||
                    !isset($_shipmentInfo["track_info"][0])) {
                    continue;
                }
                $trackInfo = $_shipmentInfo["track_info"][0];
                if (!$trackInfo["carrier_code"] || !$trackInfo["title"]) {
                    continue;
                }
                if (!isset($trackInfo["track_number"])) {
                    $trackingNumber = "N/A";
                } else {
                    $trackingNumber = $trackInfo["track_number"];
                    if (!$trackingNumber) {
                        $trackingNumber = "N/A";
                    }
                }

                $carrierCode = $trackInfo["carrier_code"];
                $className = $trackInfo["title"];
                $shippingDate = $_shipmentInfo["shipping_date"];

                $trackingDetail = [
                    "carrier_code" => $carrierCode,
                    "title" => $className,
                    "number" => $trackingNumber,
                    "created_at" => $shippingDate
                ];

                $convertOrder = $this->convertOrder;
                $shipment = $convertOrder->toShipment($order);
                $shipment->setCreatedAt($shippingDate);

                foreach ($order->getAllItems() as $orderItem) {
                    if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                        continue;
                    }
                    foreach ($_shipmentInfo["item_info"] as $itemInfo) {
                        if ($itemInfo["sku"] == $orderItem->getSku()) {
                            $qtyShipped = (int) $itemInfo["qty"];
                            $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)
                                ->setQty($qtyShipped);
                            $shipment->addItem($shipmentItem);
                        }
                    }
                }

                $track = $this->shipmentTrackFactory->create()
                    ->addData($trackingDetail);
                $shipment->addTrack($track);
                $shipment->register();
                $shipment->getOrder()->setIsInProcess(true);
                $shipment->save();
                $shipment->getOrder()->save();
                $shipmentId = $shipment->getIncrementId();
                $message = "Shipment '" . $shipmentId . "' imported for order " . $orderId;
                $this->results["response"]["data"]["success"][] = $message;
                $this->log($message);
            } catch (\Exception $e) {
                throw new WebapiException(__("create shipment " . $e->getMessage()), 0, 400);
            }
        }

        return false;
    }

    /**
     * Clean response Message
     *
     * @return void
     */
    public function cleanResponseMessages(): void
    {
        if (count($this->results["response"])) {
            foreach ($this->results["response"] as $key => $value) {
                if (isset($value["success"]) && !count($value["success"])) {
                    unset($this->results["response"][$key]["success"]);
                }
                if (isset($value["error"]) && !count($value["error"])) {
                    unset($this->results["response"][$key]["error"]);
                }
                if (isset($this->results["response"][$key]) &&
                    !count($this->results["response"][$key])) {
                    unset($this->results["response"][$key]);
                }
                if (isset($this->results["response"][$key]["success"]) &&
                    count($this->results["response"][$key]["success"])) {
                    $successData = array_unique($this->results["response"][$key]["success"]);
                    $this->results["response"][$key]["success"] = $successData;
                }
                if (isset($this->results["response"][$key]["error"]) &&
                    count($this->results["response"][$key]["error"])) {
                    $errorData = array_unique($this->results["response"][$key]["error"]);
                    $this->results["response"][$key]["error"] = $errorData;
                }
            }
        }
    }

    /**
     * Log message
     *
     * @param string $message
     * @return void
     */
    public function log(string $message): void
    {
        $logDir = $this->filesystem->getDirectoryWrite(DirectoryList::LOG);
        $writer = new \Zend_Log_Writer_Stream($logDir->getAbsolutePath('') . $this->logFile);
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info($message);
    }
}
