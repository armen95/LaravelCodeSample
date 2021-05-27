@extends('layouts.main')

@section('content')

    <div class="body newmember">
        <main>
            <header>
                <h1>{{ __('New Member') }}</h1>
            </header>

            <form action="{{ route('newmember') }}" method="post" class="accountform">
                @csrf

                @if($errors->any())
                    <div class="msg error-block errorsummary">
                        <p>{{ trans_choice('There is a problem saving your membership. Please fix these issues:|There is a problem saving your membership. Please fix these issues:',  $errors->count()) }}</p>
                        <ul>
                            @foreach($errors->all() as $error )
                                <ul>- {!! $error !!}</ul>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <p class="intro">{{ __('Submit this form to become a member.') }}</p>

                <fieldset>
                    <legend>Log In Information:</legend>
                    <p><label for="email">E-mail:</label>
                        @include('components.required-flag')
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required>
                        @include('components.form-field-error', ['field' => 'email'])
                        </p>
                    <div><p><label for="pass">Password:</label>
                            <span class="info">At least 8 characters are required.</span>
                            @include('components.required-flag')
                            <input type="password" name="password" id="password" required>@include('components.form-field-error', ['field' => 'password'])
                        </p><p><label for="password_confirmation">Confirm Password:</label>
                            @include('components.required-flag')
                            <input type="password" name="password_confirmation" id="password_confirmation" required>
                        </p>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>About You:</legend>
                    <div>
                        <p><label for="name">Name:</label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}">
                        </p><p><label for="preferred_name">What should we call you?</label>
                            <input type="text" name="preferred_name" id="preferred_name" value="{{ old('preferred_name') }}">
                        </p><p><label for="job_title">Job Title:</label>
                            <input type="text" name="job_title" id="job_title" value="{{ old('job_title') }}">
                        </p><p><label for="organization">Company Name:</label>
                            <input type="text" name="organization" id="organization" value="{{ old('organization') }}">
                        </p><p><label for="nickname">Nickname, (shown on your comments):</label>
							@include('components.required-flag')
                            <input type="text" name="nickname" id="nickname" value="{{ old('nickname') }}" required>@include('components.form-field-error', ['field' => 'nickname'])
                        </p>
                    </div>

                    <fieldset class="suaddress">
                        <legend>Address:</legend>

                        <p><label for="address">Street Address:</label>
                            <input type="text" name="address" id="address" value="{{ old('address') }}"></p>
                        <p><label for="address2">Second Line of Address, (if required):</label>
                            <input type="text" name="address2" id="address2" value="{{ old('address2') }}"></p>

                        <div><p><label for="city">City:</label>
                                <input type="text" name="city" id="city" value="{{ old('city') }}">
                            </p><p><label for="stateprov">State/Province:</label>
                                <select name="stateprov" id="stateprov">
                                    <option value="">Please Choose One</option>
									<?php print_stateprov_options( old('stateprov') ); ?>
                                </select>
                            </p>
                        </div>
                        <div>
                            <p><label for="postalcode">ZIP/Postal Code:</label>
                                <input type="text" name="postalcode" id="postalcode" value="{{ old('postalcode') }}" maxlength="10">
                            </p><p><label for="phone">Phone:</label>
                                <input type="tel" name="phone" id="phone" value="{{ old('phone') }}" maxlength="20">
                            </p>
                        </div>
                    </fieldset>
                </fieldset>

                <fieldset class="suprivacy">
                    <legend>Final Items</legend>
                    <p><label for="heard_about_us">Where did you hear about us?</label>
                        <textarea name="heard_about_us" id="heard_about_us" rows="3" cols="40">{{ old('heard_about_us') }}</textarea>
                    </p>
					<?php if( '1' == old('wants_newsletter') ) { $selector = ' checked="checked"'; } else { $selector = ''; }?>
                    <label for="wants_newsletter" class="has-checkbox"><input type="checkbox" name="wants_newsletter" id="wants_newsletter" value="1">Check to receive infrequent 4 Road Service news by E-mail.</label>
                    <p class="info">We don't want to clutter your inbox so we usually send our newsletter every four to six weeks. If we don't have anything to say we won't send a newletter.</p>


                    <p><?php if( 'yes' == old('policies') ) { $selector = ' checked="checked"'; } else { $selector = ''; } ?>
                        <label for="policies" class="has-checkbox"><input type="checkbox" name="policies" id="policies" value="yes" <?php echo $selector; ?>  >I agree to abide by the <a href="/tou" target="_blank">Terms of Use</a> and <a href="/privacy" target="_blank">Privacy Policy</a>.</label></p>
                    @include('components.form-field-error', ['field' => 'policies'])
                </fieldset>

                <button type="submit" class="affirm">Create my Account!</button>
            </form>
        </main>
    </div>

@endsection
