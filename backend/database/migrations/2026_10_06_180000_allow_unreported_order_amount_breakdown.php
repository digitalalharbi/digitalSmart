<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let an order's amount breakdown be absent, because for some providers it is.
 *
 * Salla's `GET /admin/v2/orders` returns `total: {amount, currency}` and **no breakdown at all** —
 * the subtotal, shipping, tax and discount live only on the order *details* endpoint, one request
 * per order. A sync reading a month of a busy store will often have the total and nothing else.
 *
 * Phase 11 defaulted those four columns to zero, which was wrong in exactly the way this product
 * refuses elsewhere: a stored zero reads as "nothing was charged for shipping", and a subtotal
 * report built on it would show a figure that is confidently incorrect rather than visibly absent.
 * "Not reported" and "none" are different facts, and only one of them is a reason to go looking.
 *
 * `total_minor` stays NOT NULL. An order with no total is not an order.
 */
return new class extends Migration
{
    /** The four columns that a provider may simply not report. */
    private const array OPTIONAL = ['subtotal_minor', 'discount_minor', 'shipping_minor', 'tax_minor'];

    public function up(): void
    {
        foreach (self::OPTIONAL as $column) {
            DB::statement("ALTER TABLE store_orders ALTER COLUMN {$column} DROP DEFAULT");
            DB::statement("ALTER TABLE store_orders ALTER COLUMN {$column} DROP NOT NULL");
        }

        /*
         * The amounts check has to be replaced rather than kept: the original compared NOT NULL
         * columns, and `NULL >= 0` is unknown rather than true, which would make every order with an
         * unreported breakdown fail the constraint.
         */
        DB::statement('ALTER TABLE store_orders DROP CONSTRAINT IF EXISTS store_orders_amounts_check');
        DB::statement(
            'ALTER TABLE store_orders ADD CONSTRAINT store_orders_amounts_check CHECK ('
            .'(subtotal_minor IS NULL OR subtotal_minor >= 0) '
            .'AND (discount_minor IS NULL OR discount_minor >= 0) '
            .'AND (shipping_minor IS NULL OR shipping_minor >= 0) '
            .'AND (tax_minor IS NULL OR tax_minor >= 0) '
            .'AND total_minor >= 0 AND refunded_minor >= 0)'
        );
    }

    public function down(): void
    {
        // Zero is the only value a NOT NULL column can take for a row that never reported one, which
        // is precisely the conflation this migration exists to undo. Reversing restores it.
        foreach (self::OPTIONAL as $column) {
            DB::statement("UPDATE store_orders SET {$column} = 0 WHERE {$column} IS NULL");
            DB::statement("ALTER TABLE store_orders ALTER COLUMN {$column} SET DEFAULT 0");
            DB::statement("ALTER TABLE store_orders ALTER COLUMN {$column} SET NOT NULL");
        }

        DB::statement('ALTER TABLE store_orders DROP CONSTRAINT IF EXISTS store_orders_amounts_check');
        DB::statement(
            'ALTER TABLE store_orders ADD CONSTRAINT store_orders_amounts_check CHECK ('
            .'subtotal_minor >= 0 AND discount_minor >= 0 AND shipping_minor >= 0 '
            .'AND tax_minor >= 0 AND total_minor >= 0 AND refunded_minor >= 0)'
        );
    }
};
