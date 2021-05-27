<?php

namespace App;

use App\Auditing\AuditComments;
use App\Concerns\HasAddress;
use App\Maintenance\Location;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Concerns\MayBeEphemeral;
use App\ListingMeta\UsesMeta;
use App\Pivots\ProvidesService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;

class Listing extends Model
{
	use UsesMeta, MayBeEphemeral, AuditComments, HasAddress;


	protected $table = 'listing';
	protected $primaryKey = 'ckey';


	protected $casts = [
		'is24hour' => 'boolean',
		'has_shop' => 'boolean',
		'provides_mobile_service' => 'boolean',
		'expires' => 'date'
	];

	public $fillable = [
		'fkcustomerid','description','ad_title','rank','status','expires','image1','image2','image3',
		'sbanner_file','website2','address','address2','city','county','stateprov','country',
		'postalcode','phone','tollfree','fax','cellphone','email','latitude','longitude','map_accuracy','storename',
		'permalink','is24hour',
		'permit_duplicatephone','highway','highway_exit','storenumber','preferred_service','geocode_source','payment_policy',
		'slink_facebook','slink_twitter','slink_instagram','has_shop','provides_mobile_service',
		'show_free_in_all_services'
	];

	const phoneProps = [
		'phone',
		'tollfree',
		'fax',
		'cellphone'
	];

	const phoneTypes = [
		'main' => 'Main',
		'tollfree' => 'Toll-Free',
		'after-hours' => 'After Hours',
		'phone' => 'Phone',
		'fax' => 'Fax'
	];

	const ranks = [
        3.5, 3, 2.5, 2, 1.5, 1, 0.5, 0
    ];


	public static function boot()
    {
        parent::boot();

        static::saving( function( Listing $listing) {
			$listing->beforeSave();
        });

        static::saved( function(Listing $listing) {
			$listing->afterSave();
        });

        static::deleted( function(Listing $listing) {
           $listing->afterDelete();
        });

    }

    public static function insert( $options )
	{
	    return static::create( $options );
	}

	public function resolveRouteBinding( $identifier )
	{
        $from_permalink = $this->where('permalink', '/' . $identifier)->first();
        if(!is_null($from_permalink)) {
            return $from_permalink;
        } else {
            return $this->where('ckey', $identifier)->first()?? abort('404');
        }
	}


	public function paymentMethodsAccepted() {
		return $this->belongsToMany(PaymentMethod::class, 'p4_accepts_method', $this->primaryKey, 'idPM');
	}

	public function addPaymentMethodById( $paymentmethod_id ) {
		if( $this->exists ) {
			$this->paymentMethodsAccepted()->attach($paymentmethod_id);
		} else {
			$this->paymentMethodsAccepted->add(PaymentMethod::find($paymentmethod_id));
		}
	}

	public function preferredService() {
		return $this->belongsTo(ProvidedService::class, 'preferred_service' );
	}
	public function setPreferredServiceById( $service_id ) {
		$this->preferredService()->associate(Service::find($service_id));
	}

	public function services() {
		return $this->belongsToMany(ProvidedService::class, 'provides_service', $this->primaryKey, 'idSVC' )
		            ->using(ProvidesService::class)
					->withPivot('id');
	}

	public function addServiceById( $service_id ) {
		if($this->exists) {
			$this->services()->attach($service_id);
		} else {
			$this->services->add(ProvidedService::find($service_id));
		}
	}

	public function user() {
		return $this->hasManyThrough(User::class, ProviderAccount::class, 'customerid', 'id', 'fkcustomerid');
	}

	public function account() {
		return $this->belongsTo(ProviderAccount::class, 'fkcustomerid', 'customerid' )->withDefault();
	}

	public function notes() {
		return $this->hasMany(PrivateNote::class, 'listing_id', $this->primaryKey);
	}

	public function ratings() {
		return $this->hasMany(Rating::class, 'listing_ckey', $this->primaryKey);
	}

	public function preferredBy() {
		return $this->belongsToMany(User::class, 'p4_preferred');
	}

	public function getServicesProvidedIDs() {
		static $ids = false;
		if( !$ids) {
		    $ids = $this->services->pluck('id');
		}
		return $ids;
	}

    public function getBrandsServicedIDs() {
        static $ids = false;
        if( ! $ids ) {
            $ids = [];
            foreach($this->services as $service ) {
                foreach( $service->brands as $brand ) {
                    $ids[] = [
                        $brand->id,
                        $service->id
                    ];
                }
            }
        }
        return $ids;
    }

	public function beforeSave():void
   	{
   		// Make sure the *_normalized version of each phone number is sest
   		foreach( self::phoneProps as $phonetype ) {
   			if($phonetype !== 'fax') {
			    if ( ! empty( $this->$phonetype ) ) {
				    $phonenumber = '';
				    $phone       = $this->{$phonetype};
				    if ( ! empty( $phone ) && is_object( $phone ) ) {
					    $phonenumber = $phone->number;
				    } else if ( ! empty( $phone ) ) {
					    $phonenumber = $phone;
				    }
				    $this->{$phonetype . '_normalized'} = normalizePhoneNumber( $phonenumber );
			    } else {
				    $this->{$phonetype . '_normalized'} = '';
			    }
		    }
		}

		if( ! $this->isPermalink( $this->permalink ) )
		{
			$this->permalink = $this->generatePermalink();
		}

		if( !isset($this->expires) || is_null($this->expires) || (int)mb_substr($this->expires,0,4) < 2000 )
		{
			$this->expires = (string)((int)date('Y')) . date('-m-d');
		}

		// Make sure the current Permalink isn't being saved in meta somewhere as a merged permalink, (applies to all listings, not just this one).
		DB::table('listing_meta')->where(
							[
								['meta_key','=','merged_permalink'],
								['meta_value','=',$this->permalink]
							])->delete();

		// Also make sure it's not in the current Meta about to be re-saved
		$this->deleteMeta('merged_permalink', $this->permalink);


		// JSON encode phone columns.
	    // We don't have to un-encode them later as the accessors should handle that, if needed.
		foreach( self::phoneProps as $phone_col ) {
			if(!empty($this->{$phone_col}) && is_object($this->{$phone_col})) {
				$this->{$phone_col} = json_encode($this->{$phone_col});
			}
		}

        // if we don't have any geocoding info, try to get it now.
        if(!isset( $this->map_accuracy ) || empty( $this->map_accuracy ) ) {
            $this->geocode();
        }


		if (isset($this->account)) {
		    unset($this->account);
		}

		if (is_array($this->paymentMethodsAccepted)) {
		    $this->paymentMethodsAccepted = json_encode($this->paymentMethodsAccepted);
		}
   	}

   	public function afterSave():void
   	{
	    // log this change
	    if( $this->wasRecentlyCreated )  {
	    	$logaction = 'add';
	    } else {
	    	$logaction = 'update';
	    }

	    $logid = $this->_logCurrentData($logaction);

	    if( is_object($this->preferredService) && in_array($this->preferredService->id, Service::admin_only_services )) {
            $this->addMeta('needs_attention', 'Check a category is set, (tried to set Preferred Service to a prohibited value)');
        }
    }

    public function afterDelete(): void
    {
        $this->_logCurrentData('delete');

        Storage::delete('listing-images/' . $this->image1);
        Storage::delete('listing-images/' . $this->image2);
        Storage::delete('listing-images/' . $this->image3);
        Storage::delete('listing-images/' . $this->image1);
        Storage::delete( 'searchbanners/' . $this->sbanner_file );

        // Delete related info. Others will delete with foreign key cascades.
        // Ratings should not be deleted.
        DB::table('p4_preferred')->where('listing_ckey', $this->ckey)->delete();
        DB::table('p4_note')->where('listing_id', $this->ckey)->delete();
        DB::table('listing_meta')->where('ckey', $this->ckey)->delete();

    }
// --------------------------------------------------------------------------------------

	public function isPermalink( $str ): bool
	{
		return !( empty( $str ) || !preg_match( '~^/([^/]+)/provider/([^/]+)$~', $str ) );
	}
	public function generatePermalink(): string
	{
		$state = mb_strtolower( stateAbbrToName( $this->stateprov ) );
		$citystate = url_slug( $this->city . '-' . $state );

		if ( empty( $this->storename ) ) {
			$storename = url_slug( $this->account->organization );
		} else {
			$storename = url_slug( $this->storename );
		}

		$permalink = "/$citystate/provider/$storename";

		if ( mb_strlen( $permalink ) > 350 ) {
			$permalink = mb_substr( $permalink , 0 , 350 );
		}

		$n = 0;
		while ( $this->permalinkExists( $permalink ) ) {
			if ( $n ) {
				$permalink = explode( '-', $permalink );
				$permalink[count( $permalink )-1] = $permalink[count( $permalink )-1] + 1;
				$permalink = implode('-', $permalink);
			} else {
				$n++;
				$permalink .= '-' . $n;
			}
		}
		return $permalink;
	}

	private function permalinkExists( $permalink ): bool
	{
		$count = static::where([['permalink','=',$permalink],['ckey','!=', $this->ckey]])->count();
		return (bool)$count;
	}
	protected function _logCurrentData( $action ) {

		$tolog = $this->attributes;
		$tolog['comment'] = $this->getAuditComments();
		$tolog['modifiedby'] = Auth::id();
		$tolog['action'] = $action;
		$tolog['user_ip'] = app()->runningInConsole() ? 'console' : Request::ip();

		// services offered
		$tolog['services_offered'] = $this->services->pluck('id')->implode(',');
		// payment types accepted
		$paymentMethodsAccepted = collect(json_decode($this->paymentMethodsAccepted, true));
		$tolog['payment_types_accepted'] = $paymentMethodsAccepted->pluck('idPM')->implode(',');

		// Equipment brands serviced - in JSON because it's more than a list of IDs.
		$brands = [];
		foreach( $this->services as $service ) {
			$service = (object)$service;
			if( isset( $service->brands ) ){
				foreach($service->brands as $brand) {
					$brands[] = [ 'idEB' => $brand->id, 'idSVC' => $service->id ];
				}
			}

		}
		$tolog['brands_serviced'] = json_encode( $brands );
		$tolog['listing_meta'] = json_encode( $this->_getLoggableMeta() );

		foreach( self::phoneProps as $phonetype ) {
			if(!empty($tolog[ $phonetype ]) && is_object($tolog[ $phonetype ])) {
				$tolog[ $phonetype ] = json_encode( $tolog[ $phonetype ] );
			}
		}


		$log_id = DB::table('listing_log')->insertGetId( $tolog );
		return $log_id;

	}

	public function isPreferredBy(User $user): bool
	{
		static $preferred = null;

		if(is_null($preferred)) {
			$preferred_list = $user->preferredProviders()->where( 'listing_ckey', $this->ckey )->get();
			if ( $preferred_list->isNotEmpty() ) {
				$preferred = true;
			} else {
				$preferred = false;
			}
		}
		return $preferred;
	}

	/**
	 * Gets users associated with this Listing: All users listed as managers of the listing's ProviderAccount, and the
	 * user listed as the E-mail contact.
	 * @return Collection
	 */
	public function getAssociatedUsers(): Collection
	{
		$users = $this->account->managedBy;

		if(!empty($this->email)) {
			$email_users = Users::where('email', $this->email)->get();
			$users = $users->merge($email_users);
			$users = $users->unique();
		}

		return $users;
	}

	public function distanceFrom(float $latitude, float $longitude): float
	{
		$earth_radius = 3963; // in miles

		$dLat = deg2rad($latitude - $this->latitude);
		$dLon = deg2rad($longitude - $this->longitude);

		$a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) * sin($dLon/2) * sin($dLon/2);
		$c = 2 * asin(sqrt($a));
		$d = $earth_radius * $c;

		return $d;
	}



    public function shouldRenewAutomatically(): bool
    {
        $shouldrenew = false;

        if($this->rank > 0) {

            $lastPayment = DB::table('p4_payment')
                ->select(['idPMT', 'PMT_Payplan', 'PMT_Completed'])
                ->where( [
                    ['PMT_FK', $this->ckey],
                    ['PMT_Type', 'provider']
                ])
                ->orderBy('idPMT', 'desc')
                ->first();

            if(!is_null($lastPayment) && !empty($lastPayment->idPMT)) {
                if(toUnixTimestamp($lastPayment->PMT_Completed) > strtotime('-1 year') && 'recurring' == $lastPayment->PMT_Payplan) {
                    $shouldrenew = true;
                }
            }
        }
        return $shouldrenew;
    }

    /**
    @public function acceptsPayment($processor)
     */
    public function acceptsPayment( int $processor): bool
    {
        if( $this->paymentMethodsAccepted->pluck('id')->contains($processor)) {
            return true;
        }
        return false;
    }


    /**
     * @function normalizePhoneNumber($number)
     * @param string $number : The phone number to normalize.
     *
     * - Normalizes a phone number so that we can compare it to other normalized phone numbers.
     * - Removes all non-numerical digits from the phone number.
     * - Removes the first digit if it is a 0 or 1
     *
     * @return int : The normalized phone number.
     */
    public static function normalizePhoneNumber($number) {

        $number = preg_replace('~^[01]~','',preg_replace('~\D~','',$number));
        return $number;
    }

    public function is_mobile_only(): bool
    {
        if( $this->has_shop == 0 && $this->provides_mobile_service == 1 ) {
            return true;
        }
        return false;
    }


    public function getFileNameBaseFor( int $imgnum, string $original_filename ): string
    {
        // Add microtime so uploading a new file with the same name gives a different hash
        // The hash is just for cache-busting purposes, so we get a new URL if a new file is uploaded.
        // so we can accept a relatively high probability of collisions.
        $filehash = sha1( $original_filename . microtime() );
        $filehash = mb_substr( $filehash, 0, 8 );

        if($imgnum == 2) {
            if( $this->is_mobile_only() ) {
                $filename_root = 'service-vehicle';
            } else {
                $filename_root = 'storefront';
            }
        } else if( $imgnum == 3) {
            if($this->is_mobile_only() ) {
                $filename_root = 'in-action';
            } else {
                $filename_root = 'workshop';
            }
        } else {
            $filename_root = 'store-logo';
        }


        $storename = $this->storename;
        if(empty($storename)) {
            $storename = $this->account->organization;
        }
        $storename .= ' ' . $this->city . ', ' . $this->stateprov;

        $filename = sprintf( '%1$s-%2$s-%3$s', url_slug(mb_substr($storename, 0, 80)), $filename_root, $filehash );

        return $filename;
    }

    /**
     *  Saves the images, and sets the correct filenames in the Listing's attributes, but DOES NOT save the updated
     *  filenames to the DB. Call $listing->save() after this function to persist the uploaded filenames to the DB.
     *
     * @param File[]|UploadedFile[] $images An array of uploaded files to save.
     *
     * @return int The number if images that have been saved.
     */
    function setListingImages( array $images ): int
    {
        $saved_count = 0;
        foreach( [1,2,3] as $image_idx ) {
            if(isset($images[ 'image' . $image_idx ])) {
                $image = $images['image' . $image_idx];
                if( is_a($image, File::class) || is_a($image, UploadedFile::class) ) {



                    if( method_exists($image, 'getClientOriginalName')) {
                        $filename = $image->getClientOriginalName();
                    } else {
                        $filename = $image->getFilename();
                    }

                    $filename = $this->getFileNameBaseFor($image_idx, $filename);
                    // add suffix
                    $extension = $image->extension();

                    $saved_filename = null;
                    $suffix = false;
                    do {

                        if( $saved_filename === false ) {
                            if( !$suffix ) {
                                $suffix = '-1';
                                $filename = $filename . $suffix;
                            } else {
                                $filename = explode('-', $filename);
                                $filename[ count($filename) - 1 ]++;
                                $filename = implode('-', $filename);
                            }
                        }

                        $full_filename = $filename . '.' . $extension;
                        $saved_filename = Storage::putFileAs('listing-images', $image, $full_filename);

                    } while( $saved_filename === false );



                    $old_image_file = $this->{'image' . $image_idx};

                    $this->{'image' . $image_idx} = $full_filename;

                    // The new file is saved - delete the old one!
                    if(!empty($old_image_file)) {
                        Storage::delete('listing-images/' . $old_image_file);
                    }


                    $saved_count++;
                }
            }
        }
        return $saved_count;
    }

    public function registerPayment(string $notes = ''): int
    {
        if( !isset($this->attributes['rank'])) return 0;

        // Bypass the _get() magic method so that if this is called from a ListingDisplay object, (which inherits from
        // Listing), it'll still work. ListingDisplay has an accessor that will set the rank to zero if the expiry date
        // has passed.
        $rank = (float) $this->attributes['rank'];

        $price = (float) getPriceForRank($rank);
        $discounted_price = (float) getPriceForRank($rank);

        return $this->registerCustomPayment($rank, $price, $discounted_price, $notes);
    }

    // ($ckey, $rank, $price, $disc_price, $notes = '') {
    public function registerCustomPayment(float $rank, float $price, float $discounted_price, string $notes = ''): int
    {



        // delete any non-paid payments for this listing that were created within the last 30 days.
        DB::table('p4_payment')
          ->where([
              ['PMT_FK', $this->ckey],
              ['PMT_Type', 'provider']
          ])
          ->whereRaw('PMT_Registered > DATE_SUB( NOW(), INTERVAL 30 DAY)')
          ->whereNull('PMT_Completed')
          ->delete();




        $price_arr = ["price"=>$price, "disc"=>$discounted_price];

        $payment = new LegacyOrder();
        $payment->fill([
            'PMT_FK' => $this->ckey,
            'PMT_Rank' => $rank,
            'PMT_Price' => serialize($price_arr),
            'PMT_Type' => 'provider',
            'PMT_Notes' => $notes,
        ]);
        $payment->PMT_Description = $payment->makeDescription();
        $payment->save();

        $payment_id = $payment->idPMT;

        return ($payment_id) ? $payment_id : 0;
    }

    /**
     * Gets the latitude & longitude based on the address, and saves it if it's a better accuracy than whatever we have now.
     */
    public function geocode(): void
    {
        $geocode = self::geoCodeParts($this->address, $this->city, $this->stateprov, $this->postalcode, $this->address2);
        if($geocode && isset( $geocode['accuracy'] ) && $geocode['accuracy'] >= $this->map_accuracy) {
            $this->map_accuracy = $geocode['accuracy'];
            $this->latitude = $geocode['lat'];
            $this->longitude= $geocode['lon'];
            $this->geocode_source = $geocode['source'];
        }
    }

    public function getRawRankAttribute() {
        return $this->attributes['rank'] ?? 0;
    }

    public function setServicesAndBrands( array $services ): void
    {
        // Make sure that the services exist.
        $services = array_filter($services, function($service) {
            return !is_null( ProvidedService::find($service) );
        }, ARRAY_FILTER_USE_KEY );
        


        $this->services()->sync( array_keys($services) );
    	// $this->load('services');

        foreach( $services as $service_id => $brands ) {
            // Make sure the brands exist.
            $brands = array_filter($brands, function($brand) {
               return !is_null(EquipmentBrand::find($brand));
            });

            if(!empty($brands)) {
                $service = $this->services->firstWhere('idSVC', $service_id);
                $service->brands()->sync($brands);
            }
        }
    }

    public function addAndSaveYearAtRank($rank) {
        $this->rank = floatval($rank);

        if($this->expires->isFuture()) {
            $this->expires = $this->expires->addYear();
        } else {
            $this->expires = Carbon::now()->addYear();
        }

        $this->audit_comments .= 'Set expiry & rank';
        $this->save();
    }

    public function isInternallyManaged() {
        return (
            preg_match('~^managed\+[\w]+@4roadservice.com$~', $this->email) ||
            $this->account->isInternallyManaged()
        );
    }

}
