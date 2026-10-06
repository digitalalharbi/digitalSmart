<?php

declare(strict_types=1);

use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\RevenueSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The normalised commerce domain.
 *
 * One shape for every store platform. Salla and Zid arrive in their own phases and neither gets to
 * define this schema: an agency reporting on two clients, one on each platform, needs one set of
 * columns, and the first platform integrated would otherwise become the vocabulary the second has
 * to be bent into.
 *
 * Three decisions are enforced here rather than in code, because a second write path would
 * otherwise be able to break them:
 *
 *  - **Payment and fulfilment are separate columns.** Every platform has both axes. A single
 *    `status` makes "delivered but refunded" unrepresentable, and the first thing anybody does with
 *    a single status is sum revenue by it.
 *  - **Money is integer minor units with its currency beside it.** A float would drift, and an
 *    amount with no unit is the first thing somebody adds to another one.
 *  - **Attribution evidence must be backed by a field.** An order claiming campaign parameters with
 *    no parameters on it is exactly the fiction this product must not be able to produce.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createStores();
        $this->createCustomers();
        $this->createProducts();
        $this->createOrders();
        $this->createOrderItems();
        $this->createRefunds();
        $this->createShipments();
        $this->createWebhookEvents();
    }

    public function down(): void
    {
        Schema::dropIfExists('store_webhook_events');
        Schema::dropIfExists('store_shipments');
        Schema::dropIfExists('store_refunds');
        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_orders');
        Schema::dropIfExists('store_product_variants');
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('store_customers');
        Schema::dropIfExists('stores');
    }

    private function createStores(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            /*
             * Required. A store with no client is a set of orders nobody can report on, and every
             * figure read from it would belong to no one.
             */
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('provider_connection_id')->nullable()
                ->constrained('provider_connections')->nullOnDelete();

            $table->string('provider');
            $table->string('external_id');
            $table->string('name');
            $table->string('domain')->nullable();
            /** The store's own reporting currency. Every amount below is in it. */
            $table->string('currency', 3);
            $table->string('timezone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('orders_synced_through')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            // One record per store. Two would double every order read through it.
            $table->unique(['workspace_id', 'provider', 'external_id'], 'stores_unique');
            $table->index(['workspace_id', 'client_id']);
        });

        DB::statement(
            'ALTER TABLE stores ADD CONSTRAINT stores_currency_check CHECK (char_length(currency) = 3)'
        );
    }

    private function createCustomers(): void
    {
        Schema::create('store_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained('stores')->cascadeOnDelete();

            $table->string('external_id');
            $table->string('name')->nullable();
            /*
             * The client's customers' contact details, held because matching an order to a lead in
             * Phase 15 needs them. This is personal data belonging to the agency's client, not to
             * the agency: it is never exposed through a client-portal response, and docs/SECURITY.md
             * records the retention question it raises.
             */
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();

            $table->integer('orders_count')->default(0);
            $table->bigInteger('total_spent_minor')->default(0);
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'external_id'], 'store_customers_unique');
            $table->index(['workspace_id', 'email']);
        });

        DB::statement(
            'ALTER TABLE store_customers ADD CONSTRAINT store_customers_totals_check CHECK ('
            .'orders_count >= 0 AND total_spent_minor >= 0)'
        );
    }

    private function createProducts(): void
    {
        Schema::create('store_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained('stores')->cascadeOnDelete();

            $table->string('external_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('status')->nullable();
            $table->bigInteger('price_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'external_id'], 'store_products_unique');
        });

        /** A price with no currency is a number with no unit. */
        DB::statement(
            'ALTER TABLE store_products ADD CONSTRAINT store_products_price_check CHECK ('
            .'price_minor IS NULL OR (price_minor >= 0 AND currency IS NOT NULL))'
        );

        Schema::create('store_product_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_product_id')->constrained('store_products')->cascadeOnDelete();

            $table->string('external_id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->bigInteger('price_minor')->nullable();
            $table->string('currency', 3)->nullable();
            /** Null means the platform does not track stock for it, which is not the same as zero. */
            $table->integer('stock')->nullable();
            $table->timestamps();

            $table->unique(['store_product_id', 'external_id'], 'store_product_variants_unique');
        });

        DB::statement(
            'ALTER TABLE store_product_variants ADD CONSTRAINT store_product_variants_price_check CHECK ('
            .'price_minor IS NULL OR (price_minor >= 0 AND currency IS NOT NULL))'
        );
    }

    private function createOrders(): void
    {
        Schema::create('store_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignUuid('store_customer_id')->nullable()
                ->constrained('store_customers')->nullOnDelete();

            $table->string('external_id');
            /** The number the customer and the client's staff both see. */
            $table->string('reference')->nullable();

            $table->string('payment_state');
            $table->string('fulfilment_state');
            /** The provider's own status strings, kept verbatim for when a mapping is disputed. */
            $table->string('raw_payment_status')->nullable();
            $table->string('raw_fulfilment_status')->nullable();

            $table->string('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('shipping_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->bigInteger('refunded_minor')->default(0);

            $table->string('payment_method')->nullable();
            $table->string('gateway')->nullable();

            /*
             * What the order itself carried about where it came from. Nothing inferred: the evidence
             * column records which of these fields the link actually rests on, and `none` is a
             * legitimate and common answer.
             */
            $table->string('attribution_evidence')->default(AttributionEvidence::None->value);
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('click_identifier')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('referrer')->nullable();

            /** Which authority this order's money comes from. Never merged with another. */
            $table->string('revenue_source');
            /** The honesty label every figure in this product carries. */
            $table->string('source')->default(MetricSource::Synced->value);

            $table->timestamp('placed_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'external_id'], 'store_orders_unique');
            // The two reads that matter: a client's orders over a period, and a campaign's.
            $table->index(['workspace_id', 'placed_at']);
            $table->index(['store_id', 'placed_at']);
            $table->index(['workspace_id', 'utm_campaign']);
        });

        DB::statement(sprintf(
            "ALTER TABLE store_orders ADD CONSTRAINT store_orders_payment_state_check CHECK (payment_state IN ('%s'))",
            implode("','", PaymentState::values()),
        ));
        DB::statement(sprintf(
            "ALTER TABLE store_orders ADD CONSTRAINT store_orders_fulfilment_state_check CHECK (fulfilment_state IN ('%s'))",
            implode("','", FulfilmentState::values()),
        ));
        DB::statement(sprintf(
            "ALTER TABLE store_orders ADD CONSTRAINT store_orders_revenue_source_check CHECK (revenue_source IN ('%s'))",
            implode("','", RevenueSource::values()),
        ));
        DB::statement(sprintf(
            "ALTER TABLE store_orders ADD CONSTRAINT store_orders_evidence_check CHECK (attribution_evidence IN ('%s'))",
            implode("','", AttributionEvidence::values()),
        ));

        /*
         * The constraint this table exists to carry: **evidence must be backed by a field.** An order
         * claiming campaign parameters with no parameters on it is the fiction that would let a
         * client be told which campaign earned their money on the strength of nothing, and it is the
         * one defect in this area that no report could reveal afterwards.
         */
        DB::statement(
            'ALTER TABLE store_orders ADD CONSTRAINT store_orders_evidence_is_backed CHECK ('
            ."attribution_evidence = 'none' OR utm_source IS NOT NULL OR utm_medium IS NOT NULL "
            .'OR utm_campaign IS NOT NULL OR click_identifier IS NOT NULL OR coupon_code IS NOT NULL '
            .'OR referrer IS NOT NULL)'
        );

        /*
         * Totals are not negative, and a discount is stored as a positive amount that was taken off
         * rather than as a negative one that was added. Mixing those two conventions across providers
         * is how a discount comes to increase revenue.
         */
        DB::statement(
            'ALTER TABLE store_orders ADD CONSTRAINT store_orders_amounts_check CHECK ('
            .'subtotal_minor >= 0 AND discount_minor >= 0 AND shipping_minor >= 0 '
            .'AND tax_minor >= 0 AND total_minor >= 0 AND refunded_minor >= 0)'
        );

        DB::statement(
            'ALTER TABLE store_orders ADD CONSTRAINT store_orders_currency_check CHECK (char_length(currency) = 3)'
        );
    }

    private function createOrderItems(): void
    {
        Schema::create('store_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            /*
             * Nullable: an order can name a product that has since been deleted from the catalogue,
             * and the line is still a real line of a real sale. Losing it would change history.
             */
            $table->foreignUuid('store_product_id')->nullable()
                ->constrained('store_products')->nullOnDelete();
            $table->foreignUuid('store_product_variant_id')->nullable()
                ->constrained('store_product_variants')->nullOnDelete();

            $table->string('external_id')->nullable();
            /** The name as it was at the time of sale, not as the catalogue reads today. */
            $table->string('name');
            $table->string('sku')->nullable();
            $table->integer('quantity');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->string('currency', 3);
            $table->timestamps();

            $table->index(['store_order_id']);
            $table->index(['workspace_id', 'sku']);
        });

        DB::statement(
            'ALTER TABLE store_order_items ADD CONSTRAINT store_order_items_amounts_check CHECK ('
            .'quantity > 0 AND unit_price_minor >= 0 AND discount_minor >= 0 AND total_minor >= 0)'
        );
    }

    private function createRefunds(): void
    {
        Schema::create('store_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_order_id')->constrained('store_orders')->cascadeOnDelete();

            $table->string('external_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('reason')->nullable();
            $table->timestamp('refunded_at');
            $table->timestamps();

            /*
             * Refunds are recorded individually rather than only as an order total, because "how much
             * came back and when" is a different question from "what is this order now worth", and a
             * month's refunds against last month's sales is the figure that moves a client's ROAS.
             */
            $table->index(['workspace_id', 'refunded_at']);
        });

        DB::statement(
            'ALTER TABLE store_refunds ADD CONSTRAINT store_refunds_amount_check CHECK (amount_minor > 0)'
        );
    }

    private function createShipments(): void
    {
        Schema::create('store_shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_order_id')->constrained('store_orders')->cascadeOnDelete();

            $table->string('external_id')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // Several per order, deliberately: a split delivery is two shipments of one sale.
            $table->index(['store_order_id']);
        });
    }

    private function createWebhookEvents(): void
    {
        Schema::create('store_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            /*
             * Nullable: a webhook can arrive for a store this workspace has not registered, and the
             * record of that arriving is worth keeping — it is how somebody finds out a client
             * connected a store nobody told them about.
             */
            $table->foreignUuid('store_id')->nullable()->constrained('stores')->nullOnDelete();

            $table->string('provider');
            $table->string('topic');
            /** The provider's own event id, which is what makes redelivery idempotent. */
            $table->string('external_event_id')->nullable();
            /*
             * Whether the signature checked out. Stored rather than assumed: an unverified payload is
             * kept for diagnosis and must never be processed, and the only way to be sure of that
             * afterwards is to have written down which it was.
             */
            $table->boolean('signature_verified')->default(false);
            $table->string('status')->default('received');
            $table->jsonb('payload');
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            /*
             * Idempotency, enforced rather than hoped for. Providers redeliver on any non-2xx, and a
             * redelivered order-paid event processed twice doubles a day's revenue.
             */
            $table->unique(['provider', 'external_event_id'], 'store_webhook_events_unique');
            $table->index(['workspace_id', 'received_at']);
            $table->index(['status']);
        });

        DB::statement(
            'ALTER TABLE store_webhook_events ADD CONSTRAINT store_webhook_events_status_check CHECK ('
            ."status IN ('received','queued','processed','ignored','failed','rejected'))"
        );
    }
};
