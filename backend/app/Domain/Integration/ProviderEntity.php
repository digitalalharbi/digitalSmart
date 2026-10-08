<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * The kinds of record a provider's API exposes.
 *
 * Declared per provider so the interface can say what a connection *will* bring in
 * before it brings anything in — and so a reader can see that Salla has orders and GA4
 * does not, which is the difference between a store's revenue and an analytics
 * property's.
 *
 * Only entities this product actually models appear here. A provider resource nobody
 * reads is not listed, because a list of capabilities nobody exercises is a promise.
 */
enum ProviderEntity: string
{
    // Advertising
    case Account = 'account';
    case Campaign = 'campaign';
    case AdGroup = 'ad_group';
    case Ad = 'ad';
    case Creative = 'creative';
    case Insight = 'insight';
    case Conversion = 'conversion';

    // Analytics
    case Property = 'property';
    case DataStream = 'data_stream';
    case Event = 'event';

    // Tag management
    case Container = 'container';
    case Workspace = 'workspace';
    case Tag = 'tag';
    case Trigger = 'trigger';
    case Variable = 'variable';
    case ContainerVersion = 'container_version';

    // Commerce
    case Store = 'store';
    case Order = 'order';
    case OrderItem = 'order_item';
    case Product = 'product';
    case Variant = 'variant';
    case Customer = 'customer';
    case Cart = 'cart';
    case Coupon = 'coupon';

    // CRM
    case Pipeline = 'pipeline';
    case PipelineStage = 'pipeline_stage';
    case Deal = 'deal';
    case Contact = 'contact';
    case Refund = 'refund';
    case Shipment = 'shipment';
    case Payment = 'payment';

    /** Shared */
    case WebhookEvent = 'webhook_event';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Accounts',
            self::Campaign => 'Campaigns',
            self::AdGroup => 'Ad groups',
            self::Ad => 'Ads',
            self::Creative => 'Creatives',
            self::Insight => 'Performance',
            self::Conversion => 'Conversions',
            self::Property => 'Properties',
            self::DataStream => 'Data streams',
            self::Event => 'Events',
            self::Container => 'Containers',
            self::Workspace => 'Workspaces',
            self::Tag => 'Tags',
            self::Trigger => 'Triggers',
            self::Variable => 'Variables',
            self::ContainerVersion => 'Container versions',
            self::Store => 'Stores',
            self::Order => 'Orders',
            self::OrderItem => 'Order items',
            self::Product => 'Products',
            self::Variant => 'Variants',
            self::Customer => 'Customers',
            self::Cart => 'Carts',
            self::Coupon => 'Coupons',
            self::Refund => 'Refunds',
            self::Shipment => 'Shipments',
            self::Payment => 'Payments',
            self::WebhookEvent => 'Webhook events',
            self::Pipeline => 'Pipelines',
            self::PipelineStage => 'Pipeline stages',
            self::Deal => 'Deals',
            self::Contact => 'Contacts',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
