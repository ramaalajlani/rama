<?php

namespace App\Observers;

use App\Models\Reservation;

class ReservationObserver
{
    public function created(Reservation $reservation)
    {
        // عندما ينشأ حجز، نجعل الغرفة محجوزة
        $reservation->room()->update(['status' => 'occupied']);
    }

    public function deleted(Reservation $reservation)
    {
        // عند الحذف، نجعل الغرفة في حالة تنظيف
        $reservation->room()->update(['status' => 'cleaning']);
    }
}