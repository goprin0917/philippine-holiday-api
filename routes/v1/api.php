<?php

use App\Http\Controllers\V1\CrawlerController;
use Illuminate\Support\Facades\Route;


Route::get('/crawler/fetch-page', [CrawlerController::class, 'fetchContent']);

Route::get('/crawler/crawl-years', [CrawlerController::class, 'crawlMultipleYears']);

Route::get('/crawler/debug', [CrawlerController::class, 'debug']);
