<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Keep shipping quality software.');
})->purpose('Display an inspiring quote');
