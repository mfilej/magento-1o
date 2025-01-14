<?php

declare(strict_types=1);

namespace OneO\Shop\Model;

use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Cart\AddProductsToCart as AddProductsToCartService;
use Magento\Quote\Model\Cart\ShippingMethodConverter;
use Magento\Quote\Model\Quote\TotalsCollector;
use OneO\Shop\Api\Data\ProcessDirectiveInterface;

class ProcessUpdateTaxAmountsDirective implements ProcessDirectiveInterface
{
    const ORDER_ID_KEY = 'order_id';
    private OneOGraphQLClient $graphQLClient;
    private TotalsCollector $totalsCollector;
    private ExtensibleDataObjectConverter $dataObjectConverter;
    private \OneO\Shop\Helper\CartInitializer $cartInitializer;

    /**
     * @param OneOGraphQLClient $graphQLClient
     * @param TotalsCollector $totalsCollector
     * @param ExtensibleDataObjectConverter $dataObjectConverter
     * @param \OneO\Shop\Helper\CartInitializer $cartInitializer
     */
    public function __construct(
        \OneO\Shop\Model\OneOGraphQLClient $graphQLClient,
        TotalsCollector $totalsCollector,
        ExtensibleDataObjectConverter $dataObjectConverter,
        \OneO\Shop\Helper\CartInitializer $cartInitializer
    )
    {
        $this->graphQLClient = $graphQLClient;
        $this->totalsCollector = $totalsCollector;
        $this->dataObjectConverter = $dataObjectConverter;
        $this->cartInitializer = $cartInitializer;
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function processDirective($jsonDirective): string
    {
        $arguments = $jsonDirective[self::ARGS_KEY];
        $orderId = $arguments[self::ORDER_ID_KEY];

        $graphQlClient = $this->graphQLClient->getClient();
        $oneOOrder = $graphQlClient->getOrderDetails($orderId);

        $cart = $this->cartInitializer->initializeCartFrom1oOrder($oneOOrder);
        $this->totalsCollector->collect($cart);

        $totalTax = $cart->getShippingAddress()->getTaxAmount() * 100;
        $itemTax = [];
        $cartItems = $cart->getItems();
        foreach ($oneOOrder["lineItems"] as $oneOLineItem)
        {
            foreach ($cartItems as $cartItem)
            {
                $oneOSku = $oneOLineItem["variantExternalId"] ?? $oneOLineItem["productExternalId"];
                if ($cartItem->getSku() == $oneOSku && $cartItem->getQty() == $oneOLineItem["quantity"]) {
                    $itemTax[] = [
                        "id" => $oneOLineItem["id"],
                        "tax" => $cartItem->getTaxAmount() * 100
                    ];
                }
            }

        }

        $taxes = [
            "totalTax" => $totalTax,
            "lineItems" => $itemTax,
        ];

        $graphQlClient = $this->graphQLClient->getClient();
        $graphQlClient->updateTaxes($orderId, $taxes);

        return 'ok';
    }
}