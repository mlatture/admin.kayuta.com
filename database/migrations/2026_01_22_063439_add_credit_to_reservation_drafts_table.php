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
            if (!Schema::hasColumn('reservation_drafts', 'original_cart_id')) {
                $table->string('original_cart_id')->nullable()->after('credit_amount');
            }
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
            $table->dropColumn(['original_cart_id']);
            // customer_id might have been added by another migration if it exists now, 
            // but we'll leave it for safety or drop it if we are sure we added it.
            // $table->dropColumn('customer_id'); 
        });
    }
};
