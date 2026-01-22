<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductVendorController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\NewReservationController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TaxTypeController;
use App\Http\Controllers\ProcessController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\CalendarReservationController;
use App\Http\Controllers\PayBalanceController;
use App\Http\Controllers\StationRegisterController;
use App\Http\Controllers\DynamicTableController;
use App\Http\Controllers\API\CheckAvailability;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PDFController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\CartReservationController;
use App\Http\Controllers\RateTierController;
use App\Http\Controllers\AddOnsController;
use App\Http\Controllers\BusinessSettingController;
use App\Http\Controllers\FAQController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ShortLinkController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\MeterController;
use App\Http\Controllers\Admin\AdminWaiverController;

use App\Http\Controllers\SeasonalSettingController;
use App\Http\Controllers\SeasonalRenewalGuestController;
use App\Http\Controllers\SeasonalTransactionsController;
use App\Http\Controllers\SystemLogsController;
use App\Http\Controllers\ReceiptController as NewReceiptController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ReservationManagementController;

use App\Http\Controllers\SeasonalCustomerDiscountController;

use App\Models\Order;
use App\Http\Controllers\ApiChannelController;
use App\Http\Controllers\Admin\ContentHub\SettingsController;

use App\Http\Controllers\Admin\ContentHub\ConnectionsController;
use App\Http\Controllers\Auth\SocialAuthController;

use App\Http\Controllers\ContentIdeaController;

use App\Http\Controllers\Api\IdeaController;
use App\Http\Controllers\MoneyActionController;


Route::get('/', function () {
    return redirect('/admin');
});

Auth::routes();

    Route::get('auth/{provider}/callback', [SocialAuthController::class,'callback'])
        ->name('oauth.callback');
Route::prefix('admin')
    ->middleware('auth')
    ->group(function () {





    // Page jahan Vue load hoga:
    Route::get('/ideas', function () {
        return view('admin.ideas');
    })->name('admin.ideas');

    // JSON APIs for Vue:
    Route::get('/ideas/list', [IdeaController::class, 'list'])->name('admin.ideas.list');
    Route::post('/ideas/{idea}/approve', [IdeaController::class, 'approve'])->name('admin.ideas.approve');
    Route::post('/ideas/{idea}/replace', [IdeaController::class, 'replace'])->name('admin.ideas.replace');
    Route::delete('/ideas/{idea}', [IdeaController::class, 'destroy'])->name('admin.ideas.delete');

    // content hub settings
    Route::prefix('content-hub')->group(function () {
        Route::get('/settings', [SettingsController::class, 'show'])
            ->name('admin.content-hub.settings');
        Route::post('/settings', [SettingsController::class, 'update'])
            ->name('admin.content-hub.settings.update');
        Route::post('/toggle', [SettingsController::class, 'toggle'])
            ->name('admin.content-hub.toggle');
    });
    
    
      // Connections dashboard
    Route::get('content-hub/connections', [ConnectionsController::class,'index'])
        ->name('admin.content-hub.connections');

    Route::delete('content-hub/connections/{id}', [ConnectionsController::class,'disconnect'])
        ->name('admin.content-hub.connections.disconnect');

    // OAuth
    Route::get('auth/{provider}/redirect', [SocialAuthController::class,'redirect'])
        ->name('oauth.redirect');

    Route::get('auth/{provider}/callback', [SocialAuthController::class,'callback'])
        ->name('oauth.callback');
        
        Route::prefix('seasonal-customer-discounts')->group(function () {
            Route::get('{customer}', [SeasonalCustomerDiscountController::class, 'index'])->name('seasonal.customer.discounts.index');
            Route::post('store', [SeasonalCustomerDiscountController::class, 'store'])->name('seasonal.customer.discounts.store');
            Route::post('{id}/deactivate', [SeasonalCustomerDiscountController::class, 'deactivate'])->name('seasonal.customer.discounts.deactivate');
            Route::post('{id}/activate', [SeasonalCustomerDiscountController::class, 'activate'])->name('seasonal.customer.discounts.activate');
            Route::delete('{id}/destroy', [SeasonalCustomerDiscountController::class, 'destroy'])->name('seasonal.customer.discounts.destroy');
        }) ; 


        // Reservation Management
        Route::prefix('manage/reservation')
            ->middleware('can:reservation-management')
            ->group(function () {
                Route::get('{admin}', [ReservationManagementController::class, 'index'])->name('admin.reservation_mgmt.index');
                Route::get('{admin}/availability', [ReservationManagementController::class, 'availability'])->name('admin.reservation_mgmt.availability');
                Route::post('{admin}/cart/add', [ReservationManagementController::class, 'cart'])->name('admin.reservation_mgmt.cart.add');
                Route::post('{admin}/cart/item', [ReservationManagementController::class, 'cartItems'])->name('admin.reservation_mgmt.cart.item.cartItems');
                Route::get('{admin/cart/get', [ReservationManagementController::class, 'getCart'])->name('admin.reservation_mgmt.get.cart');
                Route::delete('{admin}/cart/item/remove', [ReservationManagementController::class, 'removeCartItem'])->name('admin.reservation_mgmt.cart.item.remove');
                Route::get('{admin}/customer/search', [ReservationManagementController::class, 'customerSearch'])->name('admin.reservation_mgmt.customer.search');
                
                Route::get('{admin}/view/site/details', [ReservationManagementController::class, 'viewSiteDetails'])->name('admin.reservation_mgmt.view.site.details');

                Route::post('{admin}/customer/create', [ReservationManagementController::class, 'customerCreate'])->name('admin.reservation_mgmt.customer.create');

                Route::post('{admin}/coupon/apply', [ReservationManagementController::class, 'applyCoupon'])->name('admin.reservation_mgmt.coupon.apply');

                Route::post('{admin}/checkout', [ReservationManagementController::class, 'checkout'])->name('admin.reservation_mgmt.checkout');

                Route::post('{admin}/giftcard/lookup', [ReservationManagementController::class, 'giftCardLookup'])->name('admin.reservation_mgmt.giftcard.lookup');


                // Map View

                Route::get('{admin}/view/map', [ReservationManagementController::class, 'viewMap'])->name('admin.reservation_mgmt.map.view');
                Route::get('{admin}/view/map/information', [ReservationManagementController::class, 'information'])->name('admin.reservation_mgmt.map.information');
            });

        // Flow Reservation (Step 1 & 2)
        Route::prefix('flow-reservation')->group(function () {
            Route::get('/step-1', [\App\Http\Controllers\FlowReservationController::class, 'step1'])->name('flow-reservation.step1');
            Route::get('/search', [\App\Http\Controllers\FlowReservationController::class, 'search'])->name('flow-reservation.search');
            Route::post('/save-draft', [\App\Http\Controllers\FlowReservationController::class, 'saveDraft'])->name('flow-reservation.save-draft');
            Route::get('/step-2/{draft_id}', [\App\Http\Controllers\FlowReservationController::class, 'step2'])->name('flow-reservation.step2');
            Route::post('/update-customer/{draft_id}', [\App\Http\Controllers\FlowReservationController::class, 'updateCustomer'])->name('flow-reservation.update-customer');
            Route::post('/remove-item/{draft_id}', [\App\Http\Controllers\FlowReservationController::class, 'removeItem'])->name('flow-reservation.remove-item');
            Route::post('/finalize/{draft_id}', [\App\Http\Controllers\FlowReservationController::class, 'finalize'])->name('flow-reservation.finalize');
            Route::get('/site-details', [\App\Http\Controllers\FlowReservationController::class, 'viewSiteDetails'])->name('flow-reservation.site-details');
            Route::get('/information', [\App\Http\Controllers\FlowReservationController::class, 'information'])->name('flow-reservation.information');
            Route::post('/apply-coupon', [\App\Http\Controllers\FlowReservationController::class, 'applyCoupon'])->name('flow-reservation.apply-coupon');
        });

        Route::prefix('customers/{customer}/account')->group(function () {
            Route::get('/', [CustomerController::class, 'account'])->name('admin.customers.account');

            Route::get('/balance', [CustomerController::class, 'balance'])->name('admin.customers.account.balance');

            Route::get('/receipts', [CustomerController::class, 'receipts'])->name('admin.customers.account.receipts');

            Route::post('/send/email', [CustomerController::class, 'send'])->name('admin.customers.account.send.email');
        });

        Route::prefix('documents')->group(function () {
            Route::get('customers/{user}', [DocumentController::class, 'index'])->name('customers.documents');
            Route::get('customers/{user}/data', [DocumentController::class, 'data'])->name('customers.documents.data');

            Route::delete('{file}', [DocumentController::class, 'destroy'])->name('file.destroy');

            // Bulk delete
            Route::post('bulk-delete', [DocumentController::class, 'bulkDestroy'])->name('file.bulkDestroy');
            
            
            
             // Unattached Waivers modal (HTML partial)
    Route::get('customers/{user}/waivers', [AdminWaiverController::class, 'index'])
        ->name('customers.waivers');

    // DataTables JSON for unattached waivers (same modal me)
    Route::get('customers/{user}/waivers/data', [AdminWaiverController::class, 'data'])
        ->name('customers.waivers.data');

    // Bulk attach selected waivers to this customer
    Route::post('customers/{user}/waivers/attach', [AdminWaiverController::class, 'attach'])
        ->name('customers.waivers.attach');

    // Bulk soft delete selected waivers
    Route::post('waivers/bulk-delete', [AdminWaiverController::class, 'bulkDelete'])
        ->name('waivers.bulkDelete');

    // (Optional) Admin download (signed)
    Route::get('waivers/{waiver}/download', [AdminWaiverController::class, 'download'])
        ->name('waivers.download');
        });

        Route::get('system/logs', [SystemLogsController::class, 'index'])->name('admin.system_logs.index');

        Route::post('/receipt/save-settings', [NewReceiptController::class, 'saveSettings'])->name('receipt.save.settings');

        Route::post('receipt/upload/logo', [NewReceiptController::class, 'uploadReceiptLogo'])->name('receipt.upload.logo');

        Route::prefix('scan')->group(function () {
            Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
            Route::post('/receipts', [ReceiptController::class, 'store'])->name('receipts.store');
        });

        Route::prefix('short_links')->group(function () {
            Route::get('/', [ShortLinkController::class, 'index'])->name('shortlinks.index');
            Route::get('/create', [ShortLinkController::class, 'create'])->name('shortlinks.create');
            Route::post('/store', [ShortLinkController::class, 'store'])->name('shortlinks.store');
            Route::get('/{id}', [ShortLinkController::class, 'show'])->name('shortlinks.show');
            Route::get('/edit/{id}', [ShortLinkController::class, 'edit'])->name('shortlinks.edit');
            Route::put('/update/{id}', [ShortLinkController::class, 'update'])->name('shortlinks.update');
            Route::delete('/{id}', [ShortLinkController::class, 'destroy'])->name('shortlinks.destroy');
        });

        Route::prefix('pages')
            ->middleware(['auth'])
            ->group(function () {
                Route::get('/', [PageController::class, 'index'])->name('pages.index');
                Route::get('/create', [PageController::class, 'create'])->name('pages.create');
                Route::post('/pages', [PageController::class, 'storePages'])->name('pages.store');
                Route::post('/blogs', [PageController::class, 'storeBlogs'])->name('blogs.store');
                Route::post('/articles', [PageController::class, 'storeArticle'])->name('articles.store');
                Route::delete('/{page}', [PageController::class, 'destroy'])->name('pages.destroy');

                Route::put('/{id}', [PageController::class, 'updatePage'])->name('pages.update');
                Route::get('/{id}', [PageController::class, 'editPage'])->name('pages.edit');

                Route::get('/article/{id}', [PageController::class, 'editArticle'])->name('article.edit');
                Route::put('/article/{id}', [PageController::class, 'updateArticle'])->name('articles.update');
                Route::delete('/article/{id}', [PageController::class, 'destroyArticle'])->name('articles.destroy');

                Route::get('/blogs/{id}', [PageController::class, 'editBlogs'])->name('blogs.edit');
                Route::put('/blogs/{id}', [PageController::class, 'updateBlogs'])->name('blogs.update');
                Route::delete('/blogs/{id}', [PageController::class, 'destroyBlogs'])->name('blogs.destroy');

                Route::post('/ckeditor/upload', [PageController::class, 'uploadCkeditor'])->name('ckeditor.upload');
            });

        Route::get('faq', [FAQController::class, 'index'])->name('faq.index');
        Route::get('faq/edit/{id}', [FAQController::class, 'edit'])->name('faq.edit');
        Route::get('faq/create', [FAQController::class, 'create'])->name('faq.create');
        Route::post('faq/store', [FAQController::class, 'store'])->name('faq.store');
        Route::put('faq/update/{id}', [FAQController::class, 'update'])->name('faq.update');
        Route::delete('faq/destroy/{id}', [FAQController::class, 'destroy'])->name('faq.destroy');

        Route::post('/ai/seo-rewrite', [FAQController::class, 'aiRewrite'])->name('ai.rewrite');

        Route::post('/ai/grammar-correct', [FAQController::class, 'grammarCorrect'])->name('ai.grammar');

        Route::post('/ai/article-rewrite', [PageController::class, 'aiRewriteArticle'])->name('ai.article.rewrite');

        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users_details', [UserController::class, 'getUserName'])->name('user.name');
        Route::get('/', [HomeController::class, 'index'])->name('home');
        Route::get('/settings', [SettingController::class, 'index'])->name('settinpgs.index');
        Route::post('/settings', [SettingController::class, 'store'])->name('settings.store');
        Route::resource('product-vendors', ProductVendorController::class);
        
        
        Route::resource('content-ideas', ContentIdeaController::class)->only([
        'index',
        'create',
        'store',
        'edit',
        'update',
        'destroy',
       ]);
       
       
 

        Route::resource('products', ProductController::class);
        Route::get('category-products', [ProductController::class, 'categoryProducts'])->name('category.products');
        Route::post('/products/toggle-suggested-addon', [ProductController::class, 'toggleSuggestedAddon'])->name('products.toggle-suggested-addon');
        Route::resource('categories', CategoryController::class);
        Route::post('/products/update', [ProductController::class, 'update'])->name('products.update');
        Route::post('/products/import', [ProductController::class, 'import'])->name('products.import');
        Route::patch('/products/{product}/toggle-status', [ProductController::class, 'toggleStatus'])->name('products.toggleStatus');
        Route::patch('/products/{product}/toggle-quick-pick', [ProductController::class, 'toggleQuickPick']);
        Route::patch('/products/{product}/toggle-show-category', [ProductController::class, 'toggleShowCategory']);

        Route::get('get-categories', [CategoryController::class, 'getAllCategories'])->name('category.all');
        Route::patch('/categories/{category}/toggle-show-in-pos', [CategoryController::class, 'toggleShowInPOS']);
        Route::resource('sites', SiteController::class);
        Route::get('sites/add-image/{id}', [SiteController::class, 'addImage'])->name('sites.add-image');
        Route::delete('/sites/{site}/images/{filename}', [SiteController::class, 'deleteImage'])->name('sites.delete.image');
        Route::post('sites/upload-image/{id}', [SiteController::class, 'uploadImages'])->name('sites.upload.images');
        Route::resource('rate-tier', RateTierController::class);
        Route::get('rate-tier/add-image/{id}', [RateTierController::class, 'addImage'])->name('rate-tier.add-image');
        Route::post('rate-tier/upload-image/{id}', [RateTierController::class, 'uploadImage'])->name('rate-tier.upload.images');
        Route::delete('rate_tiers/{id}/images/{filename}', [RateTierController::class, 'deleteImage'])->name('rate_tier.delete.image');

        // Route::get('/add-ons', [AddOnsController::class, 'index'])->name('addons.index');
        // Route::get('/add-ons/edit/{id}', [AddOnsController::class, 'edit'])->name('addons.edit');
        // Route::put('/add-ons/update/{id}', [AddOnsController::class, 'update'])->name('addons.update');
        // Route::delete('/add-ons/delete/{id}', [AddOnsController::class, 'destroy'])->name('addons.destroy');
        Route::resource('addons', AddOnsController::class);

        Route::get('sites/view/{site}', [SiteController::class, 'view'])->name('sites.view');
        Route::resource('customers', CustomerController::class);
        Route::group(['middleware' => 'master_admin'], function () {
            Route::resource('organizations', OrganizationController::class)->except(['show']);
            Route::resource('admin-roles', AdminRoleController::class)->except(['show']);
            Route::resource('admins', AdminController::class)->except(['show']);
        });
        Route::resource('orders', OrderController::class);
        Route::get('orders-generate-invoice/{id}', [OrderController::class, 'generateInvoice'])->name('orders.generate.invoice');
        Route::get('orders-to-return', [OrderController::class, 'ordersToBeReturn'])->name('orders.to.be.return');
        Route::post('orders/process-refund', [ProcessController::class, 'processRefund'])->name('orders.process.refund');
        Route::post('orders/process-gift-card', [ProcessController::class, 'processGiftCard'])->name('orders.process.gift.card');
        Route::post('orders/process-gift-card-balance', [ProcessController::class, 'updateGiftCardBalance'])->name('orders.process.gift.card.balance');
        Route::post('orders/process-credit-card', [ProcessController::class, 'processCreditCard'])->name('orders.process.credit.card');
        Route::post('orders/process-terminal', [ProcessController::class, 'processTerminal'])->name('orders.process.terminal');
        Route::post('orders-submits', [OrderController::class, 'store'])->name('orders.store');
        Route::post('orders-update', [OrderController::class, 'update'])->name('orders.update');
        Route::post('orders-send-email', [OrderController::class, 'sendInvoiceEmail'])->name('orders.send.invoice');

        Route::get('reservations/book-site/{bookingId}', [ReservationController::class, 'bookSite'])->name('reservations.book.site');
        Route::get('reservations/site-detail/{siteId}/{bookingId}', [ReservationController::class, 'siteDetail'])->name('reservations.site.detail');
        Route::get('reservations/checkout/{bookingId}', [ReservationController::class, 'checkout'])->name('reservations.checkout');
        Route::post('reservations/add-to-cart', [ReservationController::class, 'addToCart'])->name('reservations.add-to-cart');
        Route::post('reservations/update-dates', [ReservationController::class, 'updateDates'])->name('reservations.updateDates');
        Route::post('reservations/update-sites', [ReservationController::class, 'updateSites'])->name('reservations.update-sites');
        Route::get('reservations/remove-cart/{bookingId}/{cartId}', [ReservationController::class, 'removeCart'])->name('reservations.remove-cart');
        Route::post('reservations/apply-coupon', [ReservationController::class, 'applyCoupon'])->name('reservations.apply-coupon');
        Route::post('reservations/do-checkout/{bookingId}', [ReservationController::class, 'doCheckout'])->name('reservations.do-checkout');

        Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::get('/reservepeople', [NewReservationController::class, 'getReservations']);
        Route::post('/reservations/update/{id}', [NewReservationController::class, 'updateReservation']);
        Route::get('get-customer-info', [CustomerController::class, 'customerInfo'])->name('customer.info');
        //Reservations
        Route::post('/postcustomer', [NewReservationController::class, 'store']);
        Route::get('/getcustomers', [NewReservationController::class, 'getCustomers']);
        Route::get('/getaddons', [NewReservationController::class, 'getAddon']);
        Route::get('/getsiteclasses', [NewReservationController::class, 'getSiteClasses']);
        Route::get('/getsitehookups', [NewReservationController::class, 'getSiteHookups']);
        Route::get('/getsite', [NewReservationController::class, 'getSites']);
        Route::get('/getnotreserve', [NewReservationController::class, 'noCart']);
        Route::post('/postinfo', [NewReservationController::class, 'storeInfo']);
        Route::post('reservations/payment/{id}/postpayment', [NewReservationController::class, 'storePayment']);
        Route::post('reservations/payment/{id}/postTerminalPayment', [NewReservationController::class, 'processPayment']);
        Route::get('reservations/payment/{id}/checkPaymentStatus', [NewReservationController::class, 'checkPaymentStatus']);
        Route::get('reservations/payment/{confirmationNumber}', [NewReservationController::class, 'paymentIndex'])->name('reservations.payment.index');
        Route::get('reservations/invoice/{confirmationNumber}', [NewReservationController::class, 'invoice'])->name('reservations.invoice');
        Route::delete('reservations/delete/add-to-cart', [NewReservationController::class, 'deleteCart'])->name('reservations.delete.add-to-cart');
        Route::get('reservation-in-cart', [NewReservationController::class, 'reservationInCart'])->name('reservations.reservation-in-cart');
        Route::delete('reservation-in-cart', [NewReservationController::class, 'clearAbandoned'])->name('cart-reservation.clear-abandoned');
        Route::get('reservations/quote-site', [NewReservationController::class, 'quoteSite'])->name('reservations.quoteSite');
        Route::get('reservations/create-reservation', [NewReservationController::class, 'createReservation'])->name('reservations.create-reservation');
        Route::get('lookup-customer', [NewReservationController::class, 'lookupCustomer'])->name('reservations.lookup-customer');
        Route::post('new-reservation/create', [NewReservationController::class, 'createNewReservation'])->name('reservations.create-new-reservation');
        Route::patch('reservations/update-availability', [NewReservationController::class, 'patchAvailability'])->name('reservations.update-availability');
        Route::get('reservations/history/{id}', [NewReservationController::class, 'reservationHistory'])->name('reservations.history');
        Route::patch('reservations/refund', [NewReservationController::class, 'refund']);
        //Cart Reservations
        Route::get('reservaitons-details', [ReservationController::class, 'details'])->name('reservations.details');
        Route::resource('cart-reservation', CartReservationController::class);
        Route::post('reservation/cancel', [CartReservationController::class, 'cancel'])->name('cancel.reservation');
        Route::get('/reservations/load-more-dates', [ReservationController::class, 'loadMoreDates'])->name('reservations.load-more-dates');

        Route::post('reservations/invoice/{id}/paybalance', [PayBalanceController::class, 'payBalance']);
        Route::post('reservations/invoice/{id}/payBalanceCredit', [PayBalanceController::class, 'processCreditCardTerminal']);
        Route::patch('reservations/update_checked_in', [NewReservationController::class, 'updateCheckedIn'])->name('reservations.updateCheckedIn');
        Route::patch('reservations/update_checked_out', [NewReservationController::class, 'updateCheckedOut'])->name('reservations.updateCheckedOut');
        // Route::resource('reservations', ReservationController::class);
        Route::get('reservations/site-details/{id}', [ReservationController::class, 'siteDetails'])->name('reservations.site-details');
        Route::resource('tax-types', TaxTypeController::class);
        Route::resource('gift-cards', GiftCardController::class);
        Route::get('reports/sales-report', [ReportController::class, 'salesReport'])->name('reports.salesReport');
        Route::get('reports/income-per-site-report', [ReportController::class, 'incomePerSiteReport'])->name('reports.incomePerSiteReport');
        Route::get('reports/z-out-report', [ReportController::class, 'zOutReport'])->name('reports.zOutReport');
        Route::get('reports/reservation-report', [ReportController::class, 'reservationReport'])->name('reports.reservationReport');
        Route::get('reports/gift-card-report', [ReportController::class, 'giftCardReport'])->name('reports.giftCardReport');
        Route::get('reports/tax-report', [ReportController::class, 'taxReport'])->name('reports.taxReport');
        Route::get('reports/payment-report', [ReportController::class, 'paymentReport'])->name('reports.paymentReport');

        Route::post('gift-cards/store', [GiftCardController::class, 'store'])->name('gift-cards.store');
        Route::post('gift-cards/apply', [GiftCardController::class, 'appltGiftCard'])->name('gift-cards.apply');
        Route::get('check-gift-card', [GiftCardController::class, 'checkGiftCard'])->name('check.gift-card');
        Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
        Route::post('/cart/change-qty', [CartController::class, 'changeQty'])->name('cart.changeQty');
        Route::delete('/cart/delete', [CartController::class, 'delete'])->name('cart.delete');
        Route::delete('/cart/empty', [CartController::class, 'empty'])->name('cart.empty');
        Route::get('cart/get-product-for-receipt', [CartController::class, 'getProductForReceipt'])->name('cart.get-product-for-receipt');
        Route::get('/cart/partialpayment', [CartController::class, 'showPartialPaymentCustomer'])->name('cart.partialpayment');
        Route::get('/cart/processCheckPayment', [CartController::class, 'processCheckPayment'])->name('cart.processCheckPayment');
        Route::post('/registers/set', [StationRegisterController::class, 'set'])->name('registers.set');
        Route::post('/registers/create', [StationRegisterController::class, 'create'])->name('registers.create');
        Route::put('/registers/rename', [StationRegisterController::class, 'rename'])->name('registers.rename');
        Route::get('/registers/get_name', [StationRegisterController::class, 'getStation'])->name('registers.station_name');
        Route::get('/registers/get', [StationRegisterController::class, 'getRegister'])->name('registers.get');
        Route::get('reservations/relocate/{id}', [CalendarReservationController::class, 'index']);

        Route::get('reservations/global-search', [\App\Http\Controllers\AdminReservationController::class, 'globalSearch'])->name('admin.reservations.globalSearch');
        
        /**
         * Update to Single Canonical Reservation View
         */ 
        Route::get('reservations/{id}', [\App\Http\Controllers\AdminReservationController::class, 'show'])->name('admin.reservations.show');
        Route::post('reservations/{id}/checkin', [\App\Http\Controllers\AdminReservationController::class, 'checkin'])->name('admin.reservations.checkin');
        Route::post('reservations/{id}/checkout', [\App\Http\Controllers\AdminReservationController::class, 'checkout'])->name('admin.reservations.checkout');

        // Money Actions
        Route::prefix('money')->group(function () {
            Route::get('move-options/{id}', [MoneyActionController::class, 'moveOptions'])->name('admin.money.move-options');
            Route::post('charge/{id}', [MoneyActionController::class, 'addCharge'])->name('admin.money.charge');
            Route::post('cancel/{id}', [MoneyActionController::class, 'cancel'])->name('admin.money.cancel');
            Route::post('move/{id}', [MoneyActionController::class, 'moveSite'])->name('admin.money.move');
            Route::post('change-dates/{id}', [MoneyActionController::class, 'changeDates'])->name('admin.money.change-dates');
            Route::get('modify/{id}', [MoneyActionController::class, 'startModification'])->name('admin.reservations.modify');
        });
        
        // Legacy Redirects
        Route::get('reservations/edit/{id}', function ($id) {
            return redirect()->route('admin.reservations.show', ['id' => $id]);
        });
        
        Route::get('reservations/details', function () {
             // If query param 'id' exists, redirect to canonical, else 404
             if (request()->has('id')) {
                 return redirect()->route('admin.reservations.show', ['id' => request('id')]);
             }
             abort(404);
        })->name('reservations.details.legacy');

        
        Route::get('reservations/unavailable-dates', [CalendarReservationController::class, 'getUnavailableDates'])->name('reservations.unavailable-dates');
        Route::post('filter-sites', [CalendarReservationController::class, 'filterSites'])->name('filter.sites');

        Route::put('update-sites-pricing', [CalendarReservationController::class, 'updateSitePricing'])->name('update.pricing');

        Route::post('insert-cards-on-files', [OrderController::class, 'insertCardsOnFiles'])->name('insert.cards.on.files');

        Route::post('/reports/z-out/download-pdf', [PDFController::class, 'generate_zOutPDF'])->name('reports.downloadPdf');

        // Survey Methods

        Route::get('/surveys/index', [SurveyController::class, 'index'])->name('surveys.index');
        Route::post('/surveys/publish', [SurveyController::class, 'store'])->name('surveys.store');
        Route::post('/surveys/store/responses', [SurveyController::class, 'storeResponses'])->name('surveys.store_responses');
        // Route::get('/surveys/{surveyId}/{email}/{siteId}/response-survey/{token}', [SurveyController::class, 'responsesurvey'])->name('surveys.response_survey');
        Route::get('/surveys/published', [SurveyController::class, 'getPublishedSurvey'])->name('surveys.get_published_survey');
        Route::get('/surveys/{survey}/edit', [SurveyController::class, 'edit'])->name('surveys.edit');
        Route::patch('update-survey-status', [SurveyController::class, 'updateStatus'])->name('surveys.update_status');
        Route::patch('/surveys/{survey}', [SurveyController::class, 'update'])->name('surveys.update');
        Route::delete('/surveys/publish-survey', [SurveyController::class, 'destroy'])->name('surveys.destroy');
        Route::get('/surveys/show-responses', [SurveyController::class, 'showResponses'])->name('surveys.show_responses');
        Route::post('/reports/z-out/download-pdf', [PDFController::class, 'generate_zOutPDF'])->name('reports.downloadPdf');

        Route::prefix('electric-meter')->group(function () {
            Route::get('/', [MeterController::class, 'index'])->name('meters.index');
            Route::post('/read', [MeterController::class, 'read'])->name('meters.read');
            Route::post('/save/reading', [MeterController::class, 'saveReading'])->name('meters.saveReading');

            Route::post('/scan', [MeterController::class, 'scan'])->name('meters.scan');
            Route::get('/unregistered', [MeterController::class, 'unregister'])->name('meters.unregistered');
            Route::post('/register', [MeterController::class, 'register'])->name('meters.register');

            Route::get('/preview/session', [MeterController::class, 'previewFromSession'])->name('meters.preview.fromSession');

            Route::prefix('bill')->group(function () {
                Route::post('/send', [MeterController::class, 'send'])->name('meters.sendBill');
            });
        });
    });

Route::prefix('admin')
    ->middleware('auth')
    ->group(static function () {
        Route::get('whitelist', [DynamicTableController::class, 'whitelist'])->name('admin.whitelist');
        Route::post('/admin/update-column-order', [DynamicTableController::class, 'updateColumnOrder'])->name('admin.update-column-order');
        Route::get('business-settings/index', [BusinessSettingController::class, 'index'])->name('admin.business-settings.index');
        Route::post('general-settings/update', [BusinessSettingController::class, 'generalUpdate'])->name('admin.general-settings.update');
        Route::post('search-settings/update', [BusinessSettingController::class, 'searchUpdate'])->name('admin.search-settings.update');
        Route::post('cart-settings/update', [BusinessSettingController::class, 'cartUpdate'])->name('admin.cart-settings.update');
        Route::post('electric-meter-rate/update', [BusinessSettingController::class, 'electricMeterRateUpdate'])->name('admin.electric-meter-rate.update');
        Route::post('dynamic-pricing-settings/update', [BusinessSettingController::class, 'dynamicPricingUpdate'])->name('admin.dynamic-pricing-settings.update');
        Route::post('platform-fee-settings/update', [BusinessSettingController::class, 'platformFeeAndDynamicUpdate'])->name('admin.platform-fee-settings.update');
        Route::post('api-channels', [ApiChannelController::class, 'store'])->name('admin.api_channels.store');
        Route::post('api-channels/{id}/rotate', [ApiChannelController::class, 'rotate'])->name('admin.api_channels.rotate');
        Route::post('api-channels/{id}/revoke', [ApiChannelController::class, 'revoke'])->name('admin.api_channels.revoke');
        Route::post('cookie-settings/update', [BusinessSettingController::class, 'cookieUpdate'])->name('admin.cookie-settings.update');
        Route::post('settinfs/cancellation-fee', [BusinessSettingController::class, 'cancellationUpdate'])->name('admin.cancellation-settings.update');
        Route::post('settings/toggle-maintenance', [BusinessSettingController::class, 'toggleMaintenance'])->name('admin.settings.toggle-maintenance');
        Route::controller(DynamicTableController::class)->group(static function () {
            Route::get('edit-table/{table}', 'edit_table')->name('admin.edit-table');
            Route::put('edit-table/{table}', 'update_table')->name('admin.update-table');
            Route::delete('delete-table/{table}', 'delete_table')->name('admin.delete-table');

            Route::get('dynamic-module-records/{table}', 'dynamic_module_records')->name('admin.dynamic-module-records');
            Route::get('dynamic-module/{table}/{id?}', 'dynamic_module_create_form_data')->name('admin.dynamic-module-create-form-data');
            Route::post('store-dynamic-module/{table}', 'dynamic_module_store_form_data')->name('admin.dynamic-module-store-form-data');
            Route::put('update-dynamic-module/{table}/{id}', 'dynamic_module_update_form_data')->name('admin.dynamic-module-update-form-data');
        });

        Route::get('reservations/relocate/{id}', [CalendarReservationController::class, 'index']);
        Route::get('reservations/unavailable-dates', [CalendarReservationController::class, 'getUnavailableDates'])->name('reservations.unavailable-dates');
        Route::get('get-data', [CheckAvailability::class, 'getData'])->name('get.data.to.push');

        Route::post('/customers/{user}/update-seasonal', [CustomerController::class, 'updateSeasonal'])->name('customers.updateSeasonal');
    });

//Seasonal Settings;

Route::prefix('seasonal')
    ->middleware('auth')
    ->group(function () {
        Route::get('settings/index', [SeasonalSettingController::class, 'index'])->name('admin.seasonal-settings.index');
        Route::post('settings/store', [SeasonalSettingController::class, 'store'])->name('admin.seasonal-settings.store');
        Route::post('renewals/trigger', [SeasonalSettingController::class, 'triggerRenewals'])->name('admin.seasonal-renewals.trigger');

        Route::post('settings/store/template', [SeasonalSettingController::class, 'storeTemplate'])->name('settings.storeTemplate');
        Route::delete('settings/destroy/template/{template}', [SeasonalSettingController::class, 'destroy'])->name('template.destroy');

        Route::prefix('renewals')->group(function () {
            Route::post('send-emails', [SeasonalTransactionsController::class, 'sendEmails'])->name('seasonal.sendEmails');
            Route::post('clear-and-reload', [SeasonalTransactionsController::class, 'clearAndReload'])->name('seasonal.reload');
            Route::post('clear', [SeasonalTransactionsController::class, 'clear'])->name('seasonal.clear');
            Route::get('statements/{email}', [SeasonalSettingController::class, 'statements'])->name('seasonal.user.statements');
            Route::get('contract/{email}', [SeasonalSettingController::class, 'viewContract'])->name('seasonal.user.view.contract');
        });

        Route::prefix('settings')->group(function () {
            Route::post('store/rate', [SeasonalSettingController::class, 'storeRate'])->name('settings.store.rate');
            Route::put('update/{rate}', [SeasonalSettingController::class, 'updateRate'])->name('settings.update.rate');
            Route::delete('destroy/rate/{rate}', [SeasonalSettingController::class, 'destroyRate'])->name('settings.destroy.rate');
        });

        Route::prefix('add-ons')->group(function () {
            Route::post('store', [SeasonalTransactionsController::class, 'storeAddOns'])->name('seasonal.addons.store');
            Route::delete('destroy/{addon}', [SeasonalTransactionsController::class, 'destroyAddOn'])->name('seasonal.addon.destroy');
        });

        Route::prefix('contracts')->group(function () {
            Route::get('download/{filename}', [SeasonalSettingController::class, 'downloadExistingContract']);
        });

        Route::prefix('guest')->group(function () {
            Route::get('{user}', [SeasonalRenewalGuestController::class, 'verifyLink'])->name('seasonal.verify.guest');
            Route::post('{user}/respond', [SeasonalRenewalGuestController::class, 'respond'])->name('seasonal.renewal.respond');
        });
    });
