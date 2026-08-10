<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin-bank-statements', function ($user) {
    return true; // ทุก admin ที่ login แล้วดูได้
});