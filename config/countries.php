<?php

use App\Countries\Australia\AustraliaModule;
use App\Countries\India\IndiaModule;
use App\Countries\NewZealand\NewZealandModule;
use App\Countries\Singapore\SingaporeModule;
use App\Countries\UnitedKingdom\UnitedKingdomModule;

return ['modules' => [
    'IN' => IndiaModule::class,
    'NZ' => NewZealandModule::class,
    'AU' => AustraliaModule::class,
    'GB' => UnitedKingdomModule::class,
    'SG' => SingaporeModule::class,
], 'default_currencies' => ['IN' => 'INR', 'NZ' => 'NZD', 'AU' => 'AUD', 'GB' => 'GBP', 'SG' => 'SGD']];
