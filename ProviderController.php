<?php

namespace App\Http\Controllers;

use App\Concerns\WorksWithListings;
use App\Http\Requests\StoreNewProviderRequest;
use App\Listing;
use App\ListingDisplay;
use App\Notifications\AdminNewProviderNotification;
use App\Notifications\ProviderWelcome;
use App\PaymentMethod;
use App\ProvidedService;
use App\ProviderAccount;
use App\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ViewErrorBag;

class ProviderController extends WebsiteController
{

    use RegistersUsers, WorksWithListings;

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     * In this case it's the combination of a Member and Listing.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request, $listinglevel = 'gold')
    {

        $this->initPage();

        $this->title = 'Become a 4 Road Service Advertiser';
        $this->description = 'Become a member of 4RoadService for FREE so you can easily keep track of the best places to fix your truck.';

        $this->setPageData('domready', [ 'RS.listingform.domready' ]);

        // queue up special JS for this page.
        $this->scripts[] = '/js/listingform.js';
        $this->scripts[] = [
            'src'  => 'https://api.tiles.mapbox.com/mapbox.js/v2.1.7/mapbox.js',
            'cb'   => 'leaflet.ready',
            'test' => 'L'
        ];

        $this->styles[] = 'https://api.tiles.mapbox.com/mapbox.js/v2.1.7/mapbox.css';


        if($request->has('listingtype')) {
            $listingtype = floatval($request->get('listingtype'));
        } else {
            switch($listinglevel) {
                case 'bronze':
                    $listingtype = 0;
                    break;
                case 'silver':
                    $listingtype = 1;
                    break;
                case 'gold':
                    $listingtype = 2;
                    break;
                case 'platinum':
                    $listingtype = 3;
                    break;
                default:
                    $listingtype = 2;
            }
        }

        if( $listingtype < 1 ) {
            $listingtype_class = ' bronze-listing-form';
        } else if( $listingtype <= 1.5 ) {
            $listingtype_class = ' silver-listing-form';
        } else if( $listingtype <= 2.5 ) {
            $listingtype_class = ' gold-listing-form';
        } else {
            $listingtype_class = ' platinum-listing-form';
        }

        $listing = new ListingDisplay();
        $listing->rank = $listingtype;

        $errors = $request->session()->get('errors', app(ViewErrorBag::class));
        $errors_messages = $errors->all();

        if (!empty($errors_messages)) {
            log_listing_form_errors($errors_messages);
        }

        return $this->view('newprovider', [
            'listing' => $listing,
            'listingtype_class' => $listingtype_class,
            'form_action' => '/newprovider'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreNewProviderRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreNewProviderRequest $request)
    {
        $validated_request_data = $request->validated();

        $validated_request_data = self::ensureServicesBrandsPaymethodsAreSet($validated_request_data);

        /**
         * @var User $user
         */
        $user = new User();
        $user->audit_comments = 'Provider signup on site';
        $user->fill($validated_request_data);
        $user->assignRole('provider');
        $user->save();
        event(new Registered($user));
        $this->guard()->login($user);

        // Not 100% sure this will work because it's M:N relationships, but we'll see.
        $account = new ProviderAccount;
        $account->audit_comments = 'Provider signup on site';

        // Get phone data for ProviderAccount
        $account_phone_data = [];
        if(isset($validated_request_data['phone_type']) && $validated_request_data['phone_type'] === 'fax') {
            $account_phone_data['fax'] = $validated_request_data['phone_number'];
        } else if(isset($validated_request_data['phone_number'])) {
            $account_phone_data['phone'] = $validated_request_data['phone_number'];
        }

        if( isset($validated_request_data['tollfree_type']) && $validated_request_data['tollfree_type'] === 'fax') {
            if(!isset($account_phone_data['fax'])) {
                $account_phone_data['fax'] = $validated_request_data['tollfree_number'];
            }
        } else if(isset($validated_request_data['tollfree_number']) && !isset($account_phone_data['phone'])) {
            $account_phone_data['phone'] = $validated_request_data['tollfree_number'];
        }

        $account->fill(array_merge($validated_request_data, $account_phone_data));
        $user->manages()->save($account);

        $validated_request_data['status'] = 'pending';

        $validated_request_data = self::transformPhoneNumbers($validated_request_data);

        unset($validated_request_data['email']);

        $listing = new Listing;
        $listing->audit_comments = 'Provider signup on site.';
        $listing->fill($validated_request_data);
        $account->listings()->save($listing);

        $listing->audit_comments = 'Save images on signup.';
        ListingController::updatedRelatedModelsAndMedia($listing, $request, $validated_request_data);

        $request->session()->flash('new_provider_created', true);

        $user->notify(new ProviderWelcome($listing));
        Notification::send(User::role('administrator')->get(), new AdminNewProviderNotification($listing));

        if($listing->rank > 0) {
            $order_id = $listing->registerPayment();
            return redirect('/checkout/order' . $order_id);
        } else {
            return redirect('/dashboard');
        }
    }
}
