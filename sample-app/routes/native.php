<?php

use App\NativeComponents\Alerts;
use App\NativeComponents\Boom;
use App\NativeComponents\Controls;
use App\NativeComponents\Form;
use App\NativeComponents\Home;
use App\NativeComponents\Items;
use App\NativeComponents\Layouts\StackLayout;
use App\NativeComponents\Orders;
use App\NativeComponents\Photos;
use App\NativeComponents\Sheet;
use App\NativeComponents\Slow;
use App\NativeComponents\Tabs;
use Illuminate\Support\Facades\Route;

Route::native('/', Home::class)->layout(StackLayout::class);
Route::native('/form', Form::class)->layout(StackLayout::class);
Route::native('/controls', Controls::class)->layout(StackLayout::class);
Route::native('/tabs', Tabs::class)->layout(StackLayout::class);
Route::native('/orders', Orders::class)->layout(StackLayout::class);
Route::native('/items', Items::class)->layout(StackLayout::class);
Route::native('/sheet', Sheet::class)->layout(StackLayout::class);
Route::native('/alerts', Alerts::class)->layout(StackLayout::class);
Route::native('/slow', Slow::class)->layout(StackLayout::class);
Route::native('/photos', Photos::class)->layout(StackLayout::class);
Route::native('/boom', Boom::class)->layout(StackLayout::class);
