<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('reservation_drafts', function (Blueprint $table) {
            $table->decimal('credit_amount', 10, 2)->default(0)->after('grand_total');
            $table->string('original_cart_id')->nullable()->after('credit_amount');
            if (!Schema::hasColumn('reservation_drafts', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('original_cart_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('reservation_drafts', function (Blueprint $table) {
            $table->dropColumn(['credit_amount', 'original_cart_id']);
            // customer_id might have been added by another migration if it exists now, 
            // but we'll leave it for safety or drop it if we are sure we added it.
            // $table->dropColumn('customer_id'); 
        });
    }
};
